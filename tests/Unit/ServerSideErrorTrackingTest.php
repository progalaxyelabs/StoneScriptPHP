<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\RequestLogging\FailureText;
use StoneScriptPHP\RequestLogging\RequestContext;
use StoneScriptPHP\RequestLogging\RequestLogger;
use StoneScriptPHP\Routing\IncomingRequest;
use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Subscriptions\SubscriptionMiddleware;

/** Handler failure whose message embeds customer data. */
final class EtThrowingRoute implements IRouteHandler
{
    public function validation_rules(): array { return []; }
    public function process(): ApiResponse
    {
        throw new \RuntimeException("duplicate key for store 'Example Chemist' owner jane.doe@example.test phone +91 98765 43210");
    }
}

/** An \Error (not an \Exception): used to escape the Router and bypass every middleware. */
final class EtTypeErrorRoute implements IRouteHandler
{
    public function validation_rules(): array { return []; }
    public function process(): ApiResponse
    {
        (static fn (int $x): int => $x)('not-an-int'); // TypeError
        return new ApiResponse('ok', '');
    }
}

/** Returns an error body and never sets a status (the classic "failure reported as success"). */
final class EtErrorBodyRoute implements IRouteHandler
{
    public function validation_rules(): array { return []; }
    public function process(): ApiResponse { return new ApiResponse('error', 'could not save'); }
}

final class EtNotOkRoute implements IRouteHandler
{
    public function validation_rules(): array { return []; }
    public function process(): ApiResponse { return new ApiResponse('not ok', 'rejected'); }
}

/** Mirrors a platform "error body can never be 2xx" guard that reads the response OBJECT. */
final class EtObjectStatusGuard implements MiddlewareInterface
{
    public function handle(array $request, callable $next): ?ApiResponse
    {
        $r = $next($request);
        if ($r->httpStatusCode === null && ($r->status === 'error' || $r->status === 'not ok')) {
            $r->httpStatusCode = $r->status === 'not ok' ? 400 : 500;
        }
        return $r;
    }
}

final class EtMarkerMiddleware implements MiddlewareInterface
{
    public static bool $sawResponse = false;
    public function handle(array $request, callable $next): ?ApiResponse
    {
        $r = $next($request);
        self::$sawResponse = true;
        return $r;
    }
}

final class EtNullMiddleware implements MiddlewareInterface
{
    public function handle(array $request, callable $next): ?ApiResponse { return null; }
}

/**
 * The server-side error tracking contract: every failure is recorded (class + safe message), none is reported as
 * success, nothing records personal data.
 */
class ServerSideErrorTrackingTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        RequestContext::reset();
        RequestLogger::reset();
        AuthContext::clear();
        EtMarkerMiddleware::$sawResponse = false;
        http_response_code(200);
        $this->logDir = sys_get_temp_dir() . '/ssp-et-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->logDir, 0755, true);
        putenv('STONESCRIPTPHP_LOG_DIR=' . $this->logDir);
    }

    protected function tearDown(): void
    {
        putenv('STONESCRIPTPHP_LOG_DIR');
        foreach (glob($this->logDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->logDir);
        RequestContext::reset();
        RequestLogger::reset();
        AuthContext::clear();
        http_response_code(200);
    }

    private function router(string $handler = EtThrowingRoute::class, string $method = 'POST'): Router
    {
        $r = new Router();
        $r->addRoute($method, '/thing', $handler, isPublic: true);
        return $r;
    }

    private function logText(): string
    {
        $out = '';
        foreach (glob($this->logDir . '/*') ?: [] as $f) { $out .= (string) file_get_contents($f); }
        return $out;
    }

    private function persistedRow(): array
    {
        $row = [];
        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $r) use (&$row): void { $row = $r; });
        RequestLogger::persistRequestLog();
        return $row;
    }

    // ---- RC2: unknown route is a 404 on the object AND the wire, even behind an object-reading guard ----

    public function test_unknown_route_is_404_not_500_even_behind_an_object_reading_guard(): void
    {
        $router = $this->router();
        $router->use(new EtObjectStatusGuard());
        $res = $router->dispatch(new IncomingRequest('GET', '/wp-includes/'));

        $this->assertSame(404, $res->httpStatusCode);
        $this->assertSame(404, http_response_code());
        $row = $this->persistedRow();
        $this->assertSame(404, $row['status']);
        $this->assertSame(RequestContext::CLASS_ERROR_RESPONSE, $row['error_class'], 'a 4xx is recorded too');
        $this->assertSame('Not found', $row['error_message']);
    }

    // ---- RC1: a handler exception is recorded with class + safe message, logged at error level ----

    public function test_handler_exception_is_a_500_with_class_recorded_and_logged_at_error_level(): void
    {
        $res = $this->router()->dispatch(new IncomingRequest('POST', '/thing'));

        $this->assertSame(500, $res->httpStatusCode);
        $row = $this->persistedRow();
        $this->assertSame(500, $row['status']);
        $this->assertSame(\RuntimeException::class, $row['error_class']);
        $this->assertNotEmpty($row['error_message']);

        $log = $this->logText();
        $this->assertStringContainsString('ERROR', $log, 'a handler failure must reach the file log at error level');
        $this->assertStringContainsString('RuntimeException', $log);
    }

    public function test_recorded_failure_and_log_carry_no_personal_data(): void
    {
        $this->router()->dispatch(new IncomingRequest('POST', '/thing'));
        $row  = $this->persistedRow();
        $seen = $row['error_message'] . "\n" . $this->logText();

        foreach (['Example Chemist', 'jane.doe', 'example.test', '98765', '43210'] as $needle) {
            $this->assertStringNotContainsString($needle, $seen, "personal data '$needle' leaked");
        }
    }

    public function test_error_throwable_is_caught_inside_the_pipeline_and_recorded(): void
    {
        $router = $this->router(EtTypeErrorRoute::class);
        $router->use(new EtMarkerMiddleware());
        $res = $router->dispatch(new IncomingRequest('POST', '/thing'));

        $this->assertSame(500, $res->httpStatusCode);
        $this->assertTrue(EtMarkerMiddleware::$sawResponse, 'the 500 must travel back through the middleware (CORS etc.)');
        $this->assertSame(\TypeError::class, $this->persistedRow()['error_class']);
    }

    // ---- failure reported as success ----

    public function test_error_body_without_status_is_never_2xx(): void
    {
        $res = $this->router(EtErrorBodyRoute::class)->dispatch(new IncomingRequest('POST', '/thing'));
        $this->assertSame(500, $res->httpStatusCode);
        $this->assertSame(500, http_response_code());

        RequestContext::reset();
        http_response_code(200);
        $res = $this->router(EtNotOkRoute::class)->dispatch(new IncomingRequest('POST', '/thing'));
        $this->assertSame(400, $res->httpStatusCode);
    }

    public function test_error_body_is_recorded_with_its_safe_message(): void
    {
        $this->router(EtErrorBodyRoute::class)->dispatch(new IncomingRequest('POST', '/thing'));
        $row = $this->persistedRow();
        $this->assertSame(500, $row['status']);
        $this->assertSame(RequestContext::CLASS_ERROR_RESPONSE, $row['error_class']);
        $this->assertSame('could not save', $row['error_message']);
    }

    public function test_middleware_that_returns_nothing_is_a_recorded_500(): void
    {
        $router = $this->router();
        $router->use(new EtNullMiddleware());
        $res = $router->dispatch(new IncomingRequest('POST', '/thing'));
        $this->assertSame(500, $res->httpStatusCode);
        $row = $this->persistedRow();
        $this->assertSame(\LogicException::class, $row['error_class']);
        $this->assertStringContainsString('did not return a response', $row['error_message']);
    }

    public function test_a_4xx_nobody_explained_is_flagged_not_silent(): void
    {
        http_response_code(503); // emitted by something that bypassed the router
        $row = $this->persistedRow();
        $this->assertSame(RequestContext::CLASS_UNRECORDED, $row['error_class']);
        $this->assertStringContainsString('503', $row['error_message']);
    }

    // ---- swallowed-error path: fail-open subscription lookup ----

    public function test_swallowed_subscription_failure_is_recorded_even_though_the_request_succeeds(): void
    {
        $_SERVER['REQUEST_URI'] = '/portal/invoices';
        AuthContext::setUser(new AuthenticatedUser(user_id: 'identity-1', tenant_id: 'tenant-abc'));
        $mw = new SubscriptionMiddleware(statusProvider: function (string $t): never {
            throw new \RuntimeException("lookup failed for tenant 'Example Pharmacy'");
        });
        $res = $mw->handle([], fn () => new ApiResponse('ok', 'passed'));

        $this->assertSame('passed', $res->message, 'fail-open behaviour is unchanged');
        $row = $this->persistedRow();
        $this->assertSame(200, $row['status']);
        $this->assertSame(\RuntimeException::class, $row['error_class'], 'a swallowed failure must not be a clean success');
        $this->assertStringStartsWith('[handled:subscription-lookup]', $row['error_message']);
        $this->assertStringNotContainsString('Example Pharmacy', $row['error_message']);
    }

    // ---- RC3: key-fallback miss is silent; failing every key is rejected ----

    public function test_foreign_issuer_token_does_not_try_the_local_key_and_logs_nothing_as_error(): void
    {
        [$private] = $this->keypair();
        $jwt = \Firebase\JWT\JWT::encode(['iss' => 'https://auth.other.example', 'sub' => 'x', 'exp' => time() + 600], $private, 'RS256');

        $local = $this->keypair();
        $identity = $this->identity($local);
        $handler = new \StoneScriptPHP\Auth\HybridApiTokenJwtHandler('http://127.0.0.1:1', 'https://auth.other.example', '/jwks', $identity);
        $this->assertFalse($handler->verifyToken($jwt), 'fails every key (JWKS unreachable) -> rejected');
        $this->assertStringNotContainsString('JWT signature invalid', $this->logText(), 'a key-fallback miss is not an error');
    }

    public function test_own_issuer_token_with_bad_signature_is_rejected_and_logged_as_warning_not_error(): void
    {
        [$otherPrivate] = $this->keypair();
        $local = $this->keypair();
        $jwt = \Firebase\JWT\JWT::encode(['iss' => 'https://api.platform.example', 'sub' => 'x', 'exp' => time() + 600], $otherPrivate, 'RS256');
        $identity = $this->identity($local);
        $handler = new \StoneScriptPHP\Auth\HybridApiTokenJwtHandler('http://127.0.0.1:1', 'https://auth.other.example', '/jwks', $identity);

        $this->assertFalse($handler->verifyToken($jwt));
        $log = $this->logText();
        $this->assertStringContainsString('WARNING', $log);
        $this->assertStringNotContainsString(' ERROR ', $log);
    }

    // ---- privacy primitives ----

    public function test_failure_text_removes_personal_data(): void
    {
        $in = "Key (email)=(a.b@x.com) already exists; store_name='Example Chemist' phone: 9876543210 token=eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxIn0.c2ln Bearer abcdefghijkl";
        $out = FailureText::safe($in);
        foreach (['a.b@x.com', 'Example Chemist', '9876543210', 'eyJhbGci', 'abcdefghijkl'] as $needle) {
            $this->assertStringNotContainsString($needle, $out);
        }
        $this->assertStringContainsString('already exists', $out, 'the cause wording survives');
    }

    public function test_logger_redacts_personal_context_keys_and_message_emails(): void
    {
        log_info('Created owner jane.doe@example.test', ['store_name' => 'Example Chemist', 'tenant_id' => 't-1']);
        $log = $this->logText();
        $this->assertStringNotContainsString('jane.doe', $log);
        $this->assertStringNotContainsString('Example Chemist', $log);
        $this->assertStringContainsString('t-1', $log, 'opaque ids stay');
    }

    public function test_registration_chatter_is_not_info_level(): void
    {
        foreach (['Auth/ExternalAuth/ExternalAuthRoutes.php', 'Subscriptions/SubscriptionRoutes.php'] as $f) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/' . $f);
            $this->assertDoesNotMatchRegularExpression('/log_info\([^;]*Registration complete/', $src);
        }
    }

    /** @param array{0:string,1:string} $pair */
    private function identity(array $pair): \StoneScriptPHP\Auth\LocalSigningIdentity
    {
        file_put_contents($this->logDir . '/priv.pem', $pair[0]);
        file_put_contents($this->logDir . '/pub.pem', $pair[1]);
        return new \StoneScriptPHP\Auth\LocalSigningIdentity(
            'https://api.platform.example', $this->logDir . '/priv.pem', null, $this->logDir . '/pub.pem', $pair[1]
        );
    }

    /** @return array{0:string,1:string} private, public PEM */
    private function keypair(): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $priv);
        return [$priv, openssl_pkey_get_details($res)['key']];
    }
}
