<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptDB\GatewayException;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Database;
use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\Persistence\DbErrorMapper;
use StoneScriptPHP\Persistence\PersistenceContract;
use StoneScriptPHP\Persistence\PersistenceException;
use StoneScriptPHP\Persistence\PersistenceLedger;
use StoneScriptPHP\Persistence\ResponseGuard;
use StoneScriptPHP\Routing\IncomingRequest;
use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\TenantDatabaseUnavailableException;

final class WriteOkRoute implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        Database::mutate('save_thing', ['x']);
        return res_ok(['saved' => true]);
    }
}

/** The bug class: swallow the failed write, answer success. */
final class SwallowingRoute implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        try {
            Database::mutate('save_thing', ['x']);
        } catch (PersistenceException $e) {
            // swallowed
        }
        return res_ok(['saved' => true]);
    }
}

/** Deliberate recovery: acknowledged, so a 2xx is legitimate. */
final class AcknowledgingRoute implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        try {
            Database::mutate('audit_log', ['x']);
        } catch (PersistenceException $e) {
            PersistenceLedger::handled($e);
        }
        return res_ok(['saved' => true]);
    }
}

final class ErrorBodyNoCodeRoute implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        return new ApiResponse('error', 'could not save');
    }
}

final class CodedPublicException extends \RuntimeException implements \StoneScriptPHP\Exceptions\PublicMessage
{
}

final class CodedRuntimeRoute implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        throw new CodedPublicException('Already closed', 409);
    }
}

final class RawDbErrorRoute implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        Database::fn('save_thing', ['x']);
        return res_ok([]);
    }
}

/**
 * L4: a failed write is never an HTTP 2xx. Covers Database::mutate() (every failure signal),
 * the DB error classifier, the response guard and both contract modes.
 */
final class PersistenceContractTest extends TestCase
{
    protected function setUp(): void
    {
        PersistenceContract::reset();
        PersistenceLedger::reset();
        DbErrorMapper::clearExtensions();
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        Database::clearFakeMode();
        PersistenceContract::reset();
        PersistenceLedger::reset();
        DbErrorMapper::clearExtensions();
        http_response_code(200);
    }

    private function fake(mixed $response, string $fn = 'save_thing'): void
    {
        Database::fake([$fn => $response]);
    }

    private function gatewayError(string $cause): GatewayException
    {
        return new GatewayException('query failed', 400, null, ['error' => 'query_failed', 'cause' => $cause]);
    }

    private function dispatch(string $routeClass, string $method = 'POST'): ApiResponse
    {
        $router = new Router();
        $router->addRoute($method, '/thing', $routeClass, isPublic: true);
        return $router->dispatch(new IncomingRequest($method, '/thing'));
    }

    /** @return list<string> */
    private function deprecations(callable $fn): array
    {
        $seen = [];
        set_error_handler(function (int $no, string $msg) use (&$seen): bool {
            $seen[] = $msg;
            return true;
        }, E_USER_DEPRECATED);
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
        return $seen;
    }

    // ---- Database::mutate(): every failure signal is a typed exception ----

    public function test_returning_row_passes_through_untouched(): void
    {
        $this->fake([['id' => 5]]);
        $this->assertSame([['id' => 5]], Database::mutate('save_thing', ['x']));
    }

    public function test_empty_result_is_a_500_persistence_failure(): void
    {
        $this->fake([]);
        try {
            Database::mutate('save_thing', ['x']);
            $this->fail('expected PersistenceException');
        } catch (PersistenceException $e) {
            $this->assertSame(500, $e->httpStatusCode());
            $this->assertSame(PersistenceException::EMPTY_RESULT, $e->reason());
            $this->assertSame('save_thing', $e->function());
            $this->assertSame(PersistenceException::DEFAULT_MESSAGE, $e->getMessage());
        }
    }

    public function test_empty_result_with_not_found_message_is_404(): void
    {
        $this->fake([]);
        try {
            Database::mutate('save_thing', ['x'], notFoundMessage: 'Bill not found.');
            $this->fail();
        } catch (PersistenceException $e) {
            $this->assertSame(404, $e->httpStatusCode());
            $this->assertSame('Bill not found.', $e->getMessage());
            $this->assertSame(PersistenceException::NOT_FOUND, $e->reason());
        }
    }

    public function test_allow_empty_returns_empty_for_idempotent_noop(): void
    {
        $this->fake([]);
        $this->assertSame([], Database::mutate('save_thing', ['x'], allowEmpty: true));
    }

    public function test_exact_rows_contract(): void
    {
        $this->fake([['id' => 1], ['id' => 2]]);
        $this->assertCount(2, Database::mutate('save_thing', ['x'], exactRows: 2));
        $this->expectException(PersistenceException::class);
        Database::mutate('save_thing', ['x'], exactRows: 3);
    }

    public function test_success_false_envelope_without_public_message_is_400_generic_and_text_stays_in_the_cause(): void
    {
        $this->fake([['success' => false, 'message' => 'Stock is too low for SKU-9918']]);
        try {
            Database::mutate('save_thing', ['x']);
            $this->fail();
        } catch (PersistenceException $e) {
            $this->assertSame(400, $e->httpStatusCode());
            $this->assertSame(PersistenceException::DEFAULT_MESSAGE, $e->getMessage());
            $this->assertFalse($e->isPublic());
            $this->assertSame('Stock is too low for SKU-9918', $e->getPrevious()?->getMessage(), 'raw text kept for platform inspection');
        }
    }

    public function test_error_envelope_is_500_even_with_allow_empty(): void
    {
        $this->fake([['error' => 'boom']]);
        try {
            Database::mutate('save_thing', ['x'], allowEmpty: true);
            $this->fail();
        } catch (PersistenceException $e) {
            $this->assertSame(500, $e->httpStatusCode());
            $this->assertSame(PersistenceException::ENVELOPE_ERROR, $e->reason());
        }
    }

    public function test_envelope_in_single_json_column_and_o_prefixed_columns(): void
    {
        $this->fake([['save_thing' => '{"error_code":"stock_low","public_message":"nope","status":409}']]);
        try {
            Database::mutate('save_thing', ['x']);
            $this->fail();
        } catch (PersistenceException $e) {
            $this->assertSame('nope', $e->getMessage());
            $this->assertSame(409, $e->httpStatusCode());
            $this->assertSame('stock_low', $e->errorCode());
        }

        $this->fake([['o_success' => false, 'o_error_code' => 'c', 'o_public_message' => 'prefixed nope']]);
        try {
            Database::mutate('save_thing', ['x']);
            $this->fail();
        } catch (PersistenceException $e) {
            $this->assertSame('prefixed nope', $e->getMessage());
            $this->assertSame(400, $e->httpStatusCode());
        }
    }

    public function test_success_true_and_null_error_columns_are_not_failures(): void
    {
        $this->fake([['success' => true, 'error' => null, 'id' => 1]]);
        $this->assertCount(1, Database::mutate('save_thing', ['x']));
    }

    public function test_database_refusal_becomes_classified_persistence_exception(): void
    {
        $this->fake(function (): never {
            throw $this->gatewayError('duplicate key value violates unique constraint "uq_things_name"');
        });
        try {
            Database::mutate('save_thing', ['x'], noun: 'item');
            $this->fail();
        } catch (PersistenceException $e) {
            $this->assertSame(409, $e->httpStatusCode());
            $this->assertSame('A item with these details already exists.', $e->getMessage());
            $this->assertStringNotContainsString('uq_things_name', $e->getMessage());
            $this->assertInstanceOf(\Throwable::class, $e->getPrevious());
        }
    }

    public function test_tenant_unavailable_keeps_its_own_type(): void
    {
        $this->fake(function (): never {
            throw new GatewayException('x', 503, null, ['error' => 'connection_failed']);
        });
        $this->expectException(TenantDatabaseUnavailableException::class);
        Database::mutate('save_thing', ['x']);
    }

    public function test_mutate_typed_passes_params_in_declaration_order(): void
    {
        $seen = null;
        $this->fake(function (array $p) use (&$seen): array {
            $seen = $p;
            return [['id' => 1]];
        });
        $params = new class {
            public string $a = 'A';
            public int $b = 2;
        };
        Database::mutateTyped('save_thing', $params);
        $this->assertSame(['A', 2], $seen);
    }

    // ---- DbErrorMapper ----

    public function test_classification_table(): void
    {
        $cases = [
            ['duplicate key value violates unique constraint', 409],
            ['insert or update on table "a" violates foreign key constraint "fk"', 400],
            ['update or delete on table "a" violates foreign key constraint "fk" on table "b" ... is still referenced', 409],
            ['new row for relation "a" violates check constraint "c"', 422],
            ['invalid input syntax for type uuid: "x"', 400],
            ['deadlock detected', 503],
            ['could not serialize access due to concurrent update', 503],
            ['column "zzz" of relation "a" does not exist', 500],
            ['null value in column "n" violates not-null constraint', 500],
            ['some plpgsql RAISE text we do not know', 500],
        ];
        foreach ($cases as [$cause, $expected]) {
            [$status, $message] = DbErrorMapper::classify($this->gatewayError($cause));
            $this->assertSame($expected, $status, $cause);
            foreach (['"a"', '"fk"', 'zzz', 'uq_'] as $leak) {
                $this->assertStringNotContainsString($leak, $message, "leaks schema detail for: $cause");
            }
        }
    }

    public function test_sqlstate_wins_over_text_for_pdo_errors(): void
    {
        $pdo = new \PDOException('whatever');
        $pdo->errorInfo = ['23505', 7, 'x'];
        $this->assertSame(409, DbErrorMapper::classify($pdo)[0]);

        $pdo2 = new \PDOException('SQLSTATE[40P01]: Deadlock detected');
        $this->assertSame(503, DbErrorMapper::classify($pdo2)[0]);
    }

    public function test_platform_extension_runs_before_builtin_table(): void
    {
        DbErrorMapper::extend(fn (string $c, ?string $n): ?array => str_contains($c, 'insufficient stock') ? [409, 'Not enough stock.'] : null);
        $this->assertSame([409, 'Not enough stock.'], DbErrorMapper::classify($this->gatewayError('Insufficient stock for item 4')));
        $this->assertSame(500, DbErrorMapper::classify($this->gatewayError('mystery'))[0]);
    }

    public function test_to_response_always_carries_a_real_error_status(): void
    {
        $r = DbErrorMapper::toResponse($this->gatewayError('deadlock detected'));
        $this->assertSame('error', $r->status);
        $this->assertSame(503, $r->httpStatusCode);
    }

    public function test_is_database_origin(): void
    {
        $this->assertTrue(DbErrorMapper::isDatabaseOrigin(new \Exception('wrapped', 0, $this->gatewayError('x'))));
        $this->assertFalse(DbErrorMapper::isDatabaseOrigin(new \LogicException('bug')));
    }

    // ---- Router + guard: PersistenceException is always mapped (no mode needed) ----

    public function test_handler_letting_persistence_exception_escape_gets_its_status(): void
    {
        $this->fake([]);
        $res = $this->dispatch(WriteOkRoute::class);
        $this->assertSame(500, $res->httpStatusCode);
        $this->assertSame('error', $res->status);

        $this->fake([['public_message' => 'Stock is too low.', 'error_code' => 'stock_low']]);
        $res = $this->dispatch(WriteOkRoute::class);
        $this->assertSame(400, $res->httpStatusCode);
        $this->assertSame('Stock is too low.', $res->message);
        $this->assertSame('stock_low', $res->data['error_code']);
    }

    // ---- ENFORCED mode ----

    public function test_enforced_swallowed_failure_cannot_answer_2xx(): void
    {
        PersistenceContract::setMode('enforced');
        $this->fake([]);
        $res = $this->dispatch(SwallowingRoute::class);
        $this->assertSame(500, $res->httpStatusCode);
        $this->assertSame('error', $res->status);
    }

    public function test_enforced_acknowledged_recovery_keeps_its_success(): void
    {
        PersistenceContract::setMode('enforced');
        $this->fake([], 'audit_log');
        $res = $this->dispatch(AcknowledgingRoute::class);
        $this->assertSame('ok', $res->status);
        $this->assertNull($res->httpStatusCode);
    }

    public function test_enforced_error_body_without_status_is_backfilled(): void
    {
        PersistenceContract::setMode('enforced');
        $res = $this->dispatch(ErrorBodyNoCodeRoute::class);
        $this->assertSame(500, $res->httpStatusCode);
    }

    public function test_enforced_not_ok_body_gets_400_and_explicit_status_is_never_overridden(): void
    {
        PersistenceContract::setMode('enforced');
        $this->assertSame(400, ResponseGuard::apply(new ApiResponse('not ok', 'x'))->httpStatusCode);
        $kept = ResponseGuard::apply(new ApiResponse('error', 'x', null, 409));
        $this->assertSame(409, $kept->httpStatusCode);
    }

    public function test_enforced_status_set_by_middleware_is_respected(): void
    {
        PersistenceContract::setMode('enforced');
        http_response_code(401);
        $r = ResponseGuard::apply(new ApiResponse('error', 'unauthorized'));
        $this->assertNull($r->httpStatusCode, 'a status a middleware already set is left to stand');
    }

    public function test_enforced_ok_responses_are_untouched(): void
    {
        PersistenceContract::setMode('enforced');
        $this->fake([['id' => 1]]);
        $res = $this->dispatch(WriteOkRoute::class);
        $this->assertSame('ok', $res->status);
    }

    public function test_enforced_raw_database_error_escaping_a_handler_is_mapped(): void
    {
        PersistenceContract::setMode('enforced');
        $this->fake(function (): never {
            throw $this->gatewayError('duplicate key value violates unique constraint "x"');
        });
        $res = $this->dispatch(RawDbErrorRoute::class);
        $this->assertSame(409, $res->httpStatusCode);
        $this->assertStringNotContainsString('"x"', $res->message);
    }

    // ---- LENIENT mode: old behaviour, plus a deprecation signal ----

    public function test_lenient_leaves_responses_alone_but_says_what_enforced_would_do(): void
    {
        $this->assertSame('lenient', PersistenceContract::mode());

        $this->fake([]);
        $notices = $this->deprecations(function (): void {
            $res = $this->dispatch(SwallowingRoute::class);
            $this->assertSame('ok', $res->status, 'lenient: historical behaviour');
        });
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('success-after-failed-write', $notices[0]);
        $this->assertStringContainsString('PERSISTENCE_CONTRACT=enforced', $notices[0]);

        PersistenceContract::reset();
        $notices = $this->deprecations(function (): void {
            $res = $this->dispatch(ErrorBodyNoCodeRoute::class);
            $this->assertNull($res->httpStatusCode);
        });
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('error-body-2xx', $notices[0]);
    }

    public function test_lenient_raw_db_error_stays_a_500(): void
    {
        $this->fake(function (): never {
            throw $this->gatewayError('duplicate key value violates unique constraint "x"');
        });
        $notices = $this->deprecations(function (): void {
            $res = $this->dispatch(RawDbErrorRoute::class);
            $this->assertSame('error', $res->status);
            $this->assertNull($res->httpStatusCode, 'lenient: not mapped to 409');
        });
        $this->assertStringContainsString('db-error-mapping', $notices[0] ?? '');
    }

    // ---- mode selection ----

    public function test_mode_selection_precedence(): void
    {
        putenv('PERSISTENCE_CONTRACT=enforced');
        $this->assertSame('enforced', PersistenceContract::mode());
        PersistenceContract::reset();
        PersistenceContract::bootstrap(['persistence' => ['contract' => 'lenient']]);
        $this->assertSame('lenient', PersistenceContract::mode());
        PersistenceContract::setMode('enforced');
        $this->assertSame('enforced', PersistenceContract::mode());
        putenv('PERSISTENCE_CONTRACT');
        $this->expectException(\InvalidArgumentException::class);
        PersistenceContract::setMode('bogus');
    }

    // ---- C1 privacy: raw database text never reaches a response or a log ----

    private const LEAKY = 'duplicate key value violates unique constraint "users_email_key" | DETAIL: Key (email)=(victim@example.com) already exists.';

    public function test_raw_db_text_never_reaches_a_response_typed_or_not(): void
    {
        $this->fake(function (): never {
            throw $this->gatewayError(self::LEAKY);
        });
        PersistenceContract::setMode('enforced');
        foreach ([WriteOkRoute::class, RawDbErrorRoute::class] as $route) {
            $res = $this->dispatch($route);
            $json = $res->toJson();
            $this->assertStringNotContainsString('victim@example.com', $json, $route);
            $this->assertStringNotContainsString('users_email_key', $json, $route);
            $this->assertStringNotContainsString('DETAIL', $json, $route);
            $this->assertSame(409, $res->httpStatusCode);
        }
    }

    public function test_lenient_debug_500_does_not_carry_db_text_either(): void
    {
        $this->fake(function (): never {
            throw $this->gatewayError(self::LEAKY);
        });
        set_error_handler(static fn (): bool => true, E_USER_DEPRECATED);
        try {
            $res = $this->dispatch(RawDbErrorRoute::class); // DEBUG_MODE is on in tests
        } finally {
            restore_error_handler();
        }
        $this->assertStringNotContainsString('victim@example.com', $res->toJson());
        $this->assertStringNotContainsString('users_email_key', $res->toJson());
    }

    public function test_error_envelope_text_is_not_returned_to_the_client(): void
    {
        $this->fake([['error' => 'Key (email)=(victim@example.com) conflict']]);
        $res = $this->dispatch(WriteOkRoute::class);
        $this->assertStringNotContainsString('victim@example.com', $res->toJson());
        $this->assertSame(500, $res->httpStatusCode);
    }

    public function test_log_sanitiser_strips_values_but_keeps_identifiers(): void
    {
        $out = \StoneScriptPHP\Persistence\LogSanitizer::sanitize(
            'duplicate key value violates unique constraint "users_email_key" | DETAIL: Key (email)=(a+b@x.io) already exists. | column "email" of relation "users"; invalid input syntax for type uuid: "secret-value"; value \'tok\''
        );
        $this->assertStringNotContainsString('a+b@x.io', $out);
        $this->assertStringNotContainsString('secret-value', $out);
        $this->assertStringNotContainsString("'tok'", $out);
        $this->assertStringContainsString('constraint "users_email_key"', $out);
        $this->assertStringContainsString('column "email"', $out);
        $this->assertStringContainsString('relation "users"', $out);
    }

    public function test_mutate_and_mapper_log_lines_never_contain_values(): void
    {
        $this->fake(function (): never {
            throw $this->gatewayError(self::LEAKY);
        });
        try {
            Database::mutate('save_thing', ['x']);
            $this->fail();
        } catch (PersistenceException $e) {
            $mutLine = \StoneScriptPHP\Persistence\Mutation::failureLine($e);
            $mapLine = DbErrorMapper::failureLine($e, DbErrorMapper::resolve($e));
            foreach ([$mutLine, $mapLine] as $line) {
                $this->assertStringNotContainsString('victim@example.com', $line);
                $this->assertStringContainsString('users_email_key', $line, 'identifiers stay for diagnosis');
            }
        }
        // an envelope-error cause is sanitised too
        $this->fake([['error' => 'Key (email)=(victim@example.com) conflict']]);
        try {
            Database::mutate('save_thing', ['x']);
            $this->fail();
        } catch (PersistenceException $e) {
            $this->assertStringNotContainsString('victim@example.com', \StoneScriptPHP\Persistence\Mutation::failureLine($e));
        }
    }

    public function test_sanitised_description_of_exception_chain(): void
    {
        $e = new \Exception('wrap', 0, new \Exception('Key (email)=(victim@example.com) bad'));
        $this->assertStringNotContainsString('victim@example.com', \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
    }

    // ---- C2 business-error convention ----

    public function test_public_envelope_surfaces_message_status_fields_and_errors_as_is(): void
    {
        $this->fake([[
            'error_code' => 'duplicate_invoice_number',
            'public_message' => 'Invoice number "INV-7" is already used.',
            'status' => 409,
            'fields' => ['invoice_number' => 'INV-7'],
            'errors' => [['line' => null, 'field' => 'invoice_number', 'message' => 'This invoice number is already in use']],
        ]]);
        $res = $this->dispatch(WriteOkRoute::class);
        $this->assertSame(409, $res->httpStatusCode);
        $this->assertSame('Invoice number "INV-7" is already used.', $res->message);
        $this->assertSame('duplicate_invoice_number', $res->data['error_code']);
        $this->assertSame(['invoice_number' => 'INV-7'], $res->data['fields']);
        $this->assertSame('invoice_number', $res->errors[0]['field']);
    }

    public function test_public_envelope_cannot_declare_a_5xx_public_status(): void
    {
        $this->fake([['public_message' => 'x', 'status' => 503]]);
        try {
            Database::mutate('save_thing', ['x']);
            $this->fail();
        } catch (PersistenceException $e) {
            $this->assertSame(400, $e->httpStatusCode());
        }
    }

    public function test_raise_public_marker_is_surfaced_and_unmarked_raise_is_not(): void
    {
        $this->fake(function (): never {
            throw $this->gatewayError('Failed to create order: [public:insufficient_quantity:409] Insufficient quantity in selected warehouse (SQLSTATE: P0001)');
        });
        try {
            Database::mutate('save_thing', ['x']);
            $this->fail();
        } catch (PersistenceException $e) {
            $this->assertSame(409, $e->httpStatusCode());
            $this->assertSame('Insufficient quantity in selected warehouse', $e->getMessage());
            $this->assertSame('insufficient_quantity', $e->errorCode());
            $this->assertTrue($e->isPublic());
        }

        $this->fake(function (): never {
            throw $this->gatewayError('Insufficient quantity in selected warehouse for item 5');
        });
        try {
            Database::mutate('save_thing', ['x']);
            $this->fail();
        } catch (PersistenceException $e) {
            $this->assertSame(500, $e->httpStatusCode());
            $this->assertStringNotContainsString('Insufficient', $e->getMessage());
        }
    }

    public function test_extension_may_return_a_full_public_error_with_dynamic_data(): void
    {
        DbErrorMapper::extend(function (string $lower, ?string $noun, string $raw, \Throwable $e): ?\StoneScriptPHP\Persistence\PublicError {
            if (!str_contains($lower, 'orders_order_number_key')) {
                return null;
            }
            return new \StoneScriptPHP\Persistence\PublicError(
                409,
                'This invoice number is already used by another invoice.',
                ['invoice_number' => 'INV-7'],
                [['field' => 'invoice_number', 'message' => 'This invoice number is already in use']],
                'duplicate_invoice_number'
            );
        });
        $r = DbErrorMapper::toResponse($this->gatewayError('duplicate key value violates unique constraint "orders_order_number_key" | DETAIL: Key (invoice_number)=(INV-7) already exists.'));
        $this->assertSame(409, $r->httpStatusCode);
        $this->assertSame('INV-7', $r->data['invoice_number']);
        $this->assertSame('duplicate_invoice_number', $r->data['error_code']);
        $this->assertSame('invoice_number', $r->errors[0]['field']);
    }

    public function test_extension_receives_raw_cause_and_throwable_and_array_form_still_works(): void
    {
        $seen = null;
        DbErrorMapper::extend(function (string $lower, ?string $noun, string $raw, \Throwable $e) use (&$seen): ?array {
            $seen = [$lower, $raw, get_class($e)];
            return str_contains($lower, 'expired on') ? [400, 'This voucher has expired.'] : null;
        });
        [$status, $msg] = DbErrorMapper::classify($this->gatewayError('Voucher 7 expired on 2026-01-01'));
        $this->assertSame([400, 'This voucher has expired.'], [$status, $msg]);
        $this->assertSame('Voucher 7 expired on 2026-01-01', $seen[1]);
    }

    public function test_cause_inspection_helpers_for_platform_catch_blocks(): void
    {
        $this->fake(function (): never {
            throw $this->gatewayError('duplicate key value violates unique constraint "orders_order_number_key"');
        });
        try {
            Database::mutate('save_thing', ['x']);
            $this->fail();
        } catch (PersistenceException $e) {
            $this->assertTrue(DbErrorMapper::causeContains($e, 'orders_order_number_key'), 'original cause survives in getPrevious()');
            $r = DbErrorMapper::publicResponse(409, 'Invoice INV-9 exists', ['invoice_number' => 'INV-9'], [['field' => 'invoice_number']], 'dup');
            $this->assertSame(409, $r->httpStatusCode);
            $this->assertSame('INV-9', $r->data['invoice_number']);
        }
    }

    public function test_coded_runtime_exception_passes_through_and_not_found_400_becomes_404(): void
    {
        // A coded exception keeps its status; its text is shown only when deliberately marked PublicMessage.
        $r = DbErrorMapper::toResponse(new CodedPublicException('Bill not allowed today', 403));
        $this->assertSame([403, 'Bill not allowed today'], [$r->httpStatusCode, $r->message]);
        $g = DbErrorMapper::toResponse(new \RuntimeException('Bill not allowed today', 403));
        $this->assertSame([403, 'Forbidden'], [$g->httpStatusCode, $g->message]);

        $nf = new PersistenceException('Customer could not be found', 400, null, PersistenceException::BUSINESS_RULE, 'f', null, true);
        $this->assertSame(404, DbErrorMapper::toResponse($nf)->httpStatusCode);
    }

    public function test_coded_runtime_exception_is_mapped_by_the_router_when_enforced(): void
    {
        PersistenceContract::setMode('enforced');
        $res = $this->dispatch(CodedRuntimeRoute::class);
        $this->assertSame(409, $res->httpStatusCode);
        $this->assertSame('Already closed', $res->message);
    }

    // ---- W6 transport, W8 ledger ----

    public function test_direct_transport_connection_failure_is_503_but_query_failure_classifies_normally(): void
    {
        $conn = new \Exception('wrapped', 0, new \StoneScriptPHP\Db\DbTransportException('refused', true));
        $this->assertSame(503, DbErrorMapper::classify($conn)[0]);

        $pdo = new \PDOException('x');
        $pdo->errorInfo = ['23505', 7, 'dup'];
        $query = new \Exception('wrapped', 0, new \StoneScriptPHP\Db\DbTransportException('failed', false, 0, $pdo));
        $this->assertSame(409, DbErrorMapper::classify($query)[0]);
    }

    public function test_ledger_is_bounded_and_reset_after_dispatch(): void
    {
        for ($i = 0; $i < 200; $i++) {
            PersistenceLedger::record(new PersistenceException('x', 500));
        }
        $this->assertCount(PersistenceLedger::MAX_ENTRIES, PersistenceLedger::unhandled());

        $this->fake([['id' => 1]]);
        $this->dispatch(WriteOkRoute::class);
        $this->assertSame([], PersistenceLedger::unhandled(), 'dispatch resets the ledger when done');
    }

    public function test_deprecation_notice_from_the_guard_cannot_throw(): void
    {
        set_error_handler(static function (int $no, string $msg): never {
            throw new \ErrorException($msg, 0, $no);
        });
        $prev = ini_set('error_log', '/dev/null');
        try {
            $res = $this->dispatch(ErrorBodyNoCodeRoute::class); // lenient: would notice
            $this->assertSame('error', $res->status);
        } finally {
            ini_set('error_log', (string) $prev);
            restore_error_handler();
        }
    }
}
