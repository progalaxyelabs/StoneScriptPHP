<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\Auth\InMemoryRefreshTokenStore;
use StoneScriptPHP\Auth\RefreshTokens\RefreshTokenIssuer;
use StoneScriptPHP\Auth\TokenClaims;
use StoneScriptPHP\Exceptions\BadRequestException;
use StoneScriptPHP\Exceptions\PublicMessage;
use StoneScriptPHP\ExceptionHandler;
use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ITypedRouteHandler;
use StoneScriptPHP\Logger;
use StoneScriptPHP\Persistence\LogSanitizer;
use StoneScriptPHP\Routing\IncomingRequest;
use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Tests\Fixtures\FakeJwt;

final class FinalReviewPublicBusinessException extends \RuntimeException implements PublicMessage
{
}

final class FinalReviewThrowingRoute implements IRouteHandler
{
    public static ?\Throwable $throw = null;

    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        throw self::$throw;
    }
}

final class FinalReviewReq
{
    public function __construct(public readonly string $x = '')
    {
    }
}

final class FinalReviewResp
{
    public function __construct(public readonly int $n = 1)
    {
    }
}

final class FinalReviewTypedRoute implements ITypedRouteHandler
{
    public static ?\Throwable $throw = null;

    public function execute(FinalReviewReq $r): FinalReviewResp
    {
        throw self::$throw;
    }
}

/** 12.0.0 final review: identity_id on the user, no raw exception text in any response, error_log sweep, sanitiser precision. */
final class Release12FinalReviewTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/ssp-fr-' . bin2hex(random_bytes(4));
        mkdir($this->logDir);
        Logger::get_instance()->configure(false, true, false, $this->logDir);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        Logger::get_instance()->configure(false, true, false, null);
        foreach (glob($this->logDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->logDir);
        http_response_code(200);
    }

    private function logText(): string
    {
        $o = '';
        foreach (glob($this->logDir . '/*') ?: [] as $f) {
            $o .= (string) file_get_contents($f);
        }
        return $o;
    }

    // ---- W1 ----

    public function test_authenticated_user_carries_the_raw_identity_id_and_it_is_not_a_custom_claim(): void
    {
        $u = AuthenticatedUser::fromPayload(['user_id' => '5', 'identity_id' => 'uuid-1', 'tenant_id' => 'tenant-a']);
        $this->assertSame('uuid-1', $u->identity_id);
        $this->assertArrayNotHasKey('identity_id', $u->customClaims);
        $this->assertNull(AuthenticatedUser::fromPayload(['user_id' => '5', 'tenant_id' => 'tenant-a'])->identity_id);
    }

    public function test_revoke_all_revokes_a_session_whose_token_has_both_identity_id_and_tenant_id(): void
    {
        $store = new InMemoryRefreshTokenStore();
        $issuer = new RefreshTokenIssuer(new FakeJwt(), $store);
        $claims = ['user_id' => '5', 'identity_id' => 'uuid-1', 'tenant_id' => 'tenant-a'];
        $s = $issuer->issueSession($claims, TokenClaims::PURPOSE_AUTHENTICATION);
        $this->assertSame('uuid-1', $store->inspect(hash('sha256', $s->refreshToken))->subject);

        // What LogoutRoute does: AuthenticatedUser built from the access-token payload -> subjectClaims().
        $user = AuthenticatedUser::fromPayload((new FakeJwt())->verifyToken($s->accessToken));
        $this->assertSame(1, $issuer->revokeAllForClaims($user->subjectClaims(), null, true));
        $this->assertFalse($store->exists(hash('sha256', $s->refreshToken)));
        $this->assertStringNotContainsString('revoke-all affected 0', $this->logText());
    }

    public function test_subject_claims_without_identity_are_tenant_qualified(): void
    {
        $u = AuthenticatedUser::fromPayload(['user_id' => '5', 'tenant_id' => 'tenant-a']);
        $this->assertSame('tenant-a#5', RefreshTokenIssuer::subjectOf($u->subjectClaims()));
    }

    // ---- W2 / W5 ----

    private function dispatch(\Throwable $e): ApiResponse
    {
        FinalReviewThrowingRoute::$throw = $e;
        $router = new Router();
        $router->addRoute('POST', '/x', FinalReviewThrowingRoute::class, isPublic: true);
        return $router->dispatch(new IncomingRequest('POST', '/x'));
    }

    private function typed(\Throwable $e): ApiResponse
    {
        FinalReviewTypedRoute::$throw = $e;
        $router = new Router();
        $m = new \ReflectionMethod($router, 'executeHandler');
        $m->setAccessible(true);
        return $m->invoke($router, new FinalReviewTypedRoute(), ['input' => ['x' => 'a'], 'params' => []]);
    }

    public function test_plain_exception_text_never_reaches_a_response_even_in_debug_mode(): void
    {
        $res = $this->dispatch(new \Exception('boom at /srv/app/secret.php for victim@example.com'));
        $json = $res->toJson();
        $this->assertStringNotContainsString('victim@example.com', $json);
        $this->assertStringNotContainsString('secret.php', $json);
        $this->assertSame(500, http_response_code(), 'the real HTTP status is 500 (lenient mode leaves the envelope status unset)');
        $this->assertSame('Internal server error', $res->message);
        $cid = $res->data['correlation_id'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $cid);
        $this->assertStringContainsString($cid, $this->logText());
        $this->assertStringNotContainsString('victim@example.com', $this->logText());
    }

    public function test_typed_handler_coded_runtime_exception_is_generic_unless_marked_public(): void
    {
        $res = $this->typed(new \RuntimeException('row 4711 of invoices for victim@example.com missing', 404));
        $this->assertSame(404, $res->httpStatusCode);
        $this->assertSame('Not found', $res->message);
        $this->assertStringNotContainsString('victim@example.com', $res->toJson());
        $this->assertArrayHasKey('correlation_id', $res->data);

        $res = $this->typed(new \RuntimeException('internal 500 detail secret', 500));
        $this->assertSame('Internal server error', $res->message);
        $this->assertStringNotContainsString('secret', $res->toJson());

        $res = $this->typed(new FinalReviewPublicBusinessException('That invoice number is taken.', 409));
        $this->assertSame(409, $res->httpStatusCode);
        $this->assertSame('That invoice number is taken.', $res->message, 'deliberately public messages are shown as written');
    }

    public function test_coded_runtime_exception_through_the_db_mapper_is_generic_unless_public(): void
    {
        $r = \StoneScriptPHP\Persistence\DbErrorMapper::resolve(new \RuntimeException('raw internal text', 403));
        $this->assertSame(403, $r->status);
        $this->assertSame('Forbidden', $r->message);
        $p = \StoneScriptPHP\Persistence\DbErrorMapper::resolve(new FinalReviewPublicBusinessException('You may not do that here.', 403));
        $this->assertSame('You may not do that here.', $p->message);
    }

    public function test_exception_handler_shows_public_framework_messages_unmodified_and_fatal_has_a_correlation_id(): void
    {
        $h = ExceptionHandler::getInstance();
        $m = new \ReflectionMethod($h, 'buildErrorResponse');
        $m->setAccessible(true);
        $msg = 'Field "email" is not valid (value: \'x\') - see "docs"';
        $body = $m->invoke($h, new BadRequestException($msg), 400, true, 'cid000000001');
        $this->assertSame($msg, $body['message'], 'not sanitised: it is a deliberately public message');
        $this->assertInstanceOf(PublicMessage::class, new BadRequestException('x'));

        $body = $m->invoke($h, new \Exception('plain internal text'), 500, true, 'cid000000002');
        $this->assertSame('An error occurred', $body['message']);

        $src = (string) file_get_contents(__DIR__ . '/../../src/ExceptionHandler.php');
        $this->assertStringContainsString("\$response['correlation_id'] = \$correlationId;", $src);
        $this->assertSame(2, substr_count($src, "\$response['correlation_id']"), 'both the exception and the fatal-error renderers carry one');
    }

    // ---- W3: error_log sweep ----

    public function test_no_error_log_call_in_src_carries_exception_text_or_raw_identifiers(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src', \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        foreach ($it as $file) {
            if (!preg_match('/\.(php|template)$/', $file->getFilename())) {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if ($file->getFilename() !== 'Logger.php' && preg_match('/(?<![\w>])error_log\(/', $line) && !str_contains($line, 'WebhookQuarantine')
                    && (str_contains($line, 'getMessage()') || preg_match('/tenant=\{|tenant_id\}|payment=\{|payment_id=/', $line))) {
                    $offenders[] = basename($file->getPathname()) . ':' . ($n + 1);
                }
            }
        }
        // Logger.php is the sink and DeprecationNotice's last-resort fallback is already sanitised via describe().
        $this->assertSame([], $offenders);
    }

    public function test_subscription_logs_use_short_refs_not_raw_ids(): void
    {
        $this->assertMatchesRegularExpression('/^ref:[0-9a-f]{8}$/', LogSanitizer::ref('tenant-uuid-1'));
        $this->assertSame(LogSanitizer::ref('a'), LogSanitizer::ref('a'));
        $this->assertNotSame(LogSanitizer::ref('a'), LogSanitizer::ref('b'));
        foreach (['Subscriptions/SubscriptionMiddleware.php', 'Subscriptions/Routes/PostAdminActivateRoute.php', 'Subscriptions/Routes/PostRazorpayWebhookRoute.php'] as $f) {
            $src = (string) file_get_contents(__DIR__ . '/../../src/' . $f);
            $this->assertStringContainsString('LogSanitizer::ref(', $src, $f);
        }
    }

    // ---- W4: sanitiser precision ----

    public function test_json_debug_line_keeps_its_structure_and_prose_quotes_survive(): void
    {
        log_info('Binding failed: {"errors":[{"line":1,"field":"email","message":"required"}]}');
        log_info("Route 'x' registered; user's session didn't start");
        $log = $this->logText();
        $this->assertStringContainsString('{"errors":[{"line":1,"field":"email","message":"required"}]}', $log);
        $this->assertStringContainsString("Route 'x' registered", $log);
    }

    public function test_db_shaped_text_is_still_masked(): void
    {
        $s = LogSanitizer::forLog('duplicate key value violates unique constraint "users_email_key" | DETAIL: Key (email)=(a@b.io) already exists. invalid input syntax for type uuid: "secret-value"');
        $this->assertStringNotContainsString('a@b.io', $s);
        $this->assertStringNotContainsString('secret-value', $s);
        $this->assertStringContainsString('users_email_key', $s);
        $this->assertStringNotContainsString('Key (email)', LogSanitizer::forLog('Key (email)=(a@b)'));
    }

    public function test_the_word_detail_is_not_a_redaction_trigger_only_the_postgres_detail_form(): void
    {
        $this->assertSame('see detail: the page footer', LogSanitizer::forLog('see detail: the page footer'));
        $this->assertSame('order detail=full', LogSanitizer::sanitize('order detail=full'));
        $this->assertStringContainsString('DETAIL: <redacted>', LogSanitizer::sanitize('x | DETAIL: Key (a)=(b) exists'));
    }
}
