<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptDB\GatewayException;
use StoneScriptPHP\Db\DbTransportException;
use StoneScriptPHP\Persistence\DbErrorMapper;
use StoneScriptPHP\Persistence\PublicRaiseLint;

/**
 * The `[public:code:status] text` RAISE marker publishes its text to the API client. It must be honoured ONLY when it
 * is the start of the PostgreSQL primary message, never when it is found in DETAIL / HINT / CONTEXT, where PostgreSQL
 * echoes user-submitted values and other columns of the row.
 */
final class PublicMarkerTest extends TestCase
{
    protected function setUp(): void
    {
        DbErrorMapper::clearPublicPrefixes();
    }

    protected function tearDown(): void
    {
        DbErrorMapper::clearPublicPrefixes();
    }

    private function gw(string $cause): GatewayException
    {
        return new GatewayException('Gateway returned HTTP 500: query_failed', 500, null, ['error' => 'query_failed', 'cause' => $cause]);
    }

    public function testMarkerInUserValueEchoedInDetailIsNotPublic(): void
    {
        // a check violation: the user's `name` field is a marker, PostgreSQL echoes the whole row (incl. other columns)
        $e = $this->gw('new row for relation "users" violates check constraint "users_age_check" | DETAIL: Failing row contains (7, [public:x:404] attacker text, victim@example.test, secret-column-value, -5). | SCHEMA: public | TABLE: users');
        [$status, $message] = DbErrorMapper::classify($e);
        $this->assertSame(422, $status, 'the status comes from the SQLSTATE class, not from attacker text');
        $this->assertStringNotContainsString('attacker', $message);
        $this->assertStringNotContainsString('victim', $message);
        $this->assertStringNotContainsString('secret-column-value', $message);
        $this->assertStringNotContainsString('Failing row', $message);
        $r = DbErrorMapper::resolve($e);
        $this->assertFalse($r->public ?? false);
        $this->assertNull($r->errorCode);
    }

    public function testMarkerInDetailHintContextOfARaiseIsNotPublic(): void
    {
        foreach (['DETAIL', 'HINT', 'CONTEXT'] as $field) {
            $e = $this->gw("Something failed | $field: [public:x:404] attacker text");
            [$status, $message] = DbErrorMapper::classify($e);
            $this->assertSame(500, $status, $field);
            $this->assertStringNotContainsString('attacker', $message, $field);
        }
        // inline rendering used by some drivers
        $e = $this->gw('Something failed DETAIL:  [public:x:404] attacker text');
        $this->assertSame(500, DbErrorMapper::classify($e)[0]);
    }

    public function testRegisteredPrefixDeveloperFormWorksAndUnregisteredDoesNot(): void
    {
        $cause = 'Failed to create order: [public:insufficient_quantity:409] Insufficient quantity in selected warehouse (SQLSTATE: P0001)';

        // not registered: not public
        $r = DbErrorMapper::resolve($this->gw($cause));
        $this->assertSame(500, $r->status);
        $this->assertStringNotContainsString('Insufficient', $r->message);

        // registered exactly: public, text bounded by the end of the line / (SQLSTATE
        DbErrorMapper::allowPublicPrefix('Failed to create order: ');
        $r = DbErrorMapper::resolve($this->gw($cause));
        $this->assertSame(409, $r->status);
        $this->assertSame('Insufficient quantity in selected warehouse', $r->message);
        $this->assertSame('insufficient_quantity', $r->errorCode);

        // a different prefix, or the registered one with extra text around it, is still not public
        foreach (['Failed to create invoice: [public:x:409] hi', 'Oops. Failed to create order: [public:x:409] hi', 'failed to create order: [public:x:409] hi'] as $other) {
            $this->assertSame(500, DbErrorMapper::resolve($this->gw($other))->status, $other);
        }

        // with a structured P0001 SQLSTATE too
        $e = new GatewayException('x', 500, null, ['error' => 'query_failed', 'sqlstate' => 'P0001', 'cause' => 'Failed to create order: [public:rejected:422] Not allowed | DETAIL: [public:x:404] attacker text']);
        $r = DbErrorMapper::resolve($e);
        $this->assertSame([422, 'Not allowed'], [$r->status, $r->message]);
    }

    public function testUserMarkerBeforeTheAuthorsTextIsNotPublic(): void
    {
        // RAISE 'Phone % is already registered to %', p_phone, v_owner   with p_phone = '[public:x:409] x'
        $e = $this->gw('Phone [public:x:409] x is already registered to Alice Victim');
        $r = DbErrorMapper::resolve($e);
        $this->assertSame(500, $r->status);
        $this->assertStringNotContainsString('Alice', $r->message);
        $this->assertNull($r->errorCode);

        // even with a registered prefix, text between the prefix and the marker is not allowed
        DbErrorMapper::allowPublicPrefix('Failed to create order: ');
        $r = DbErrorMapper::resolve($this->gw('Failed to create order: Phone [public:x:409] x is already registered to Alice Victim'));
        $this->assertSame(500, $r->status);
        $this->assertStringNotContainsString('Alice', $r->message);
    }

    public function testValueContainingErrorPrefixCannotMoveTheExtractionPoint(): void
    {
        foreach ([
            'Phone ERROR:  [public:x:409] x is registered to Alice Victim',                                            // gateway form
            "SQLSTATE[P0001]: Raise exception: 7 ERROR:  Phone ERROR:  [public:x:409] x is registered to Alice Victim", // pdo form
            'pg_query(): Query failed: ERROR:  Phone ERROR:  [public:x:409] x is registered to Alice Victim',           // pg_* warning form
        ] as $cause) {
            $r = DbErrorMapper::resolve($this->gw($cause));
            $this->assertSame(500, $r->status, $cause);
            $this->assertStringNotContainsString('Alice', $r->message, $cause);
        }
        // and the genuine driver forms still work (prefixes stripped only at the very start)
        $this->assertSame(410, DbErrorMapper::resolve($this->gw("SQLSTATE[P0001]: Raise exception: 7 ERROR:  [public:gone:410] It is gone\nCONTEXT: x"))->status);
        $this->assertSame(410, DbErrorMapper::resolve($this->gw("ERROR:  [public:gone:410] It is gone"))->status);
    }

    public function testPgandroidBridgeFormKeepsAWorkingMarker(): void
    {
        $e = DbTransportException::fromDriverFailure('pgandroid bridge call failed', new \RuntimeException('SQLSTATE[P0001]: [public:gone:410] msg'), false);
        $r = DbErrorMapper::resolve($e);
        $this->assertSame([410, 'msg', 'gone'], [$r->status, $r->message, $r->errorCode]);
        // a forged one in the echoed value of a system error does not
        $e = DbTransportException::fromDriverFailure('pgandroid bridge call failed', new \RuntimeException('SQLSTATE[22P02]: invalid input syntax for type uuid: "[public:gone:410] msg"'), false);
        $this->assertNotSame(410, DbErrorMapper::resolve($e)->status);
    }

    public function testRaiseStartingWithAPlaceholderIsTheLintsJobNotTheRuntimes(): void
    {
        // At runtime `RAISE '% is taken', v` with v = '[public:x:409] hi' is byte-for-byte a developer marker: it
        // CANNOT be told apart from one. That is why the lint forbids a RAISE that starts with an interpolated value.
        $r = DbErrorMapper::resolve($this->gw('[public:x:409] hi is taken'));
        $this->assertSame(409, $r->status, 'documented residual: indistinguishable at runtime');
        $hits = PublicRaiseLint::scanDetailed("RAISE EXCEPTION '% is taken', p_name;");
        $this->assertSame('leading-interpolation', $hits[0]['rule'] ?? null);
    }

    public function testForgedMarkerInsideFailingRowDetailIsNotPublic(): void
    {
        $e = $this->gw('new row for relation "orders" violates check constraint "qty_positive" | DETAIL: Failing row contains (12, Failed to save: [public:x:404] attacker text, 3). | SCHEMA: public | TABLE: orders');
        $r = DbErrorMapper::resolve($e);
        $this->assertSame(422, $r->status);
        $this->assertStringNotContainsString('attacker', $r->message);
        $this->assertNull($r->errorCode);
    }

    public function testMarkerMustBeOnThePrimaryLineAndNotEchoedByPostgres(): void
    {
        $cases = [
            "line1\n[public:x:404] attacker text",                                             // second line
            'invalid input syntax for type uuid: "[public:x:404] attacker text"',               // echoed value, system error (22P02)
            'value "[public:x:404] attacker text" is bogus',                                    // echoed in quotes, unknown phrasing
            'malformed thing (see [public:x:404] attacker text)',                               // echoed in parentheses
            'key=[public:x:404] attacker text',                                                 // echoed after =
            'plain words [public:x:404] attacker text',                                         // arbitrary prefix text
        ];
        foreach ($cases as $cause) {
            $r = DbErrorMapper::resolve($this->gw($cause));
            $this->assertStringNotContainsString('attacker', $r->message, $cause);
            $this->assertNotSame(404, $r->status, $cause);
        }
        // a system SQLSTATE supplied by the gateway disqualifies the marker outright
        $e = new GatewayException('x', 500, null, ['error' => 'query_failed', 'sqlstate' => '23514', 'cause' => '[public:x:404] attacker text']);
        $this->assertStringNotContainsString('attacker', DbErrorMapper::resolve($e)->message);
    }

    public function testRealMarkerOnThePrimaryLineStillWorks(): void
    {
        $r = DbErrorMapper::resolve($this->gw('[public:out_of_stock:409] Sorry, sold out | DETAIL: [public:x:404] attacker text'));
        $this->assertSame(409, $r->status);
        $this->assertSame('Sorry, sold out', $r->message, 'text is bounded by the cause separators');
        $this->assertSame('out_of_stock', $r->errorCode);

        $r = DbErrorMapper::resolve($this->gw('[public:no_status] hello'));
        $this->assertSame(400, $r->status);
        $this->assertSame('hello', $r->message);
    }

    public function testDriverWrappedTextIsAlsoPrimaryLineOnly(): void
    {
        // pdo / pg_last_error style wrapping
        $raw = "SQLSTATE[P0001]: Raise exception: 7 ERROR:  [public:gone:410] It is gone\nCONTEXT:  PL/pgSQL function f() line 3 at RAISE\nDETAIL: [public:x:404] attacker text";
        $e = DbTransportException::fromDriverFailure('Direct database function call failed', new \RuntimeException($raw), false);
        $r = DbErrorMapper::resolve($e);
        $this->assertSame(410, $r->status);
        $this->assertSame('It is gone', $r->message);

        $raw = "SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation \"users\" violates check constraint \"c\"\nDETAIL:  Failing row contains ([public:x:404] attacker text, 1).";
        $e = DbTransportException::fromDriverFailure('Direct database function call failed', new \RuntimeException($raw), false);
        [$status, $message] = DbErrorMapper::classify($e);
        $this->assertSame(422, $status);
        $this->assertStringNotContainsString('attacker', $message);
    }

    public function testLintFlagsValueInterpolationInAPublicRaise(): void
    {
        $bad = "CREATE FUNCTION f(p text) RETURNS void AS \$\$\nBEGIN\n    RAISE EXCEPTION '[public:x:409] user % exists', p;\nEND;\n\$\$ LANGUAGE plpgsql;\n";
        $this->assertSame([3], PublicRaiseLint::scan($bad));
        $this->assertSame([1], PublicRaiseLint::scan("RAISE '[public] hi %', v;"));

        $good = "RAISE EXCEPTION '[public:x:409] already exists';\nRAISE EXCEPTION '[public:x:409] 100%% sure';\nRAISE EXCEPTION 'slug_taken: %', p;\nRAISE EXCEPTION 'plain % value', p;";
        $this->assertSame([], PublicRaiseLint::scan($good), 'only [public..] messages are linted for interpolation; %% is a literal percent');
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function lintForms(): array
    {
        return [
            'USING MESSAGE concatenation' => ["RAISE EXCEPTION USING MESSAGE = '[public:x:409] user ' || p_name || ' exists';", true],
            'USING MESSAGE format()' => ["RAISE EXCEPTION USING MESSAGE = format('[public:x:409] user %s exists', p_name);", true],
            'USING MESSAGE format() multiline' => ["RAISE EXCEPTION USING\n  ERRCODE = 'P0001',\n  MESSAGE = format(\n    '[public:x:409] user %L', p_name\n  ), HINT = 'x';", true],
            'USING MESSAGE literal only' => ["RAISE EXCEPTION USING MESSAGE = '[public:x:409] already here', ERRCODE = 'P0001';", false],
            'E-string with %' => ["RAISE EXCEPTION E'[public:x:409] user % exists\\n', p;", true],
            'E-string clean' => ["RAISE EXCEPTION E'[public:x:409] already\\nhere';", false],
            'dollar-quoted with %' => ["RAISE EXCEPTION \$m\$[public:x:409] user % exists\$m\$, p;", true],
            'dollar-quoted clean' => ["RAISE EXCEPTION \$m\$[public:x:409] already here\$m\$;", false],
            'SQLSTATE form with %' => ["RAISE SQLSTATE '22012' USING MESSAGE = '[public:x:409] user ' || p;", true],
            'leading placeholder' => ["RAISE EXCEPTION '% is taken', p;", true],
            'leading placeholder via USING MESSAGE' => ["RAISE EXCEPTION USING MESSAGE = p_name || ' is taken';", true],
            'leading placeholder via format()' => ["RAISE EXCEPTION USING MESSAGE = format('%s is taken', p_name);", true],
            'code first, value later' => ["RAISE EXCEPTION 'slug_taken: %', p_slug;", false],
            'comment is ignored' => ["-- RAISE EXCEPTION '[public:x:409] user % exists', p;\nRAISE EXCEPTION 'not_found';", false],
            'text message with the word RAISE' => ["SELECT 'we RAISE concerns';", false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lintForms')]
    public function testLintHandlesEveryMessageForm(string $sql, bool $flagged): void
    {
        $this->assertSame($flagged, PublicRaiseLint::scan($sql) !== [], $sql);
    }

    public function testLintHonoursTheIgnoreFileDirectiveOnlyWhenPresent(): void
    {
        $this->assertSame([], PublicRaiseLint::scan("-- stonescript-lint: ignore-file\nRAISE EXCEPTION '% x', p;"));
        $this->assertNotSame([], PublicRaiseLint::scan("RAISE EXCEPTION '% x', p;"));
    }

    public function testBundledFixturesAreLintClean(): void
    {
        $this->assertSame([], PublicRaiseLint::scanDirectory(__DIR__ . '/../E2E/fixtures/app'));
        $this->assertSame([], PublicRaiseLint::scanDirectory(dirname(__DIR__, 2) . '/src/Templates'));
    }
}
