<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Routing\IncomingRequest;
use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\IRouteHandler;

/**
 * Regression/feature guard for TESTABILITY-SPEC.md T1-1: Router::dispatch()
 * accepts an optional IncomingRequest so route-level tests (method matching,
 * header-driven middleware, request body shape) can run without touching PHP
 * superglobals or php://input, and without a live HTTP server.
 *
 * Every test here also proves dispatch(null) — the default, used by every
 * production call site — is unaffected: superglobal-reading behavior is
 * exercised explicitly in test_dispatch_without_incoming_request_still_reads_superglobals().
 */

/** Echoes back its bound properties so tests can assert on what the router bound. */
class EchoInputRoute implements IRouteHandler
{
    public ?string $name = null;
    public ?int $count = null;

    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        return new ApiResponse('ok', 'echo', ['name' => $this->name, 'count' => $this->count]);
    }
}

/** Captures the request array the middleware pipeline actually received. */
class CapturingMiddleware implements MiddlewareInterface
{
    public static ?array $lastRequest = null;

    public function handle(array $request, callable $next): ?ApiResponse
    {
        self::$lastRequest = $request;
        return $next($request);
    }
}

class RouterIncomingRequestTest extends TestCase
{
    protected function tearDown(): void
    {
        CapturingMiddleware::$lastRequest = null;
        parent::tearDown();
    }

    public function test_get_route_resolves_input_from_incoming_query_not_globals(): void
    {
        $router = new Router();
        $router->get('/echo', EchoInputRoute::class);

        // Deliberately populate $_GET with a DIFFERENT value to prove the
        // injected IncomingRequest wins over superglobals, not the other way
        // around.
        $_GET = ['name' => 'from-superglobal', 'count' => '999'];

        $incoming = new IncomingRequest(
            method: 'GET',
            path: '/echo',
            query: ['name' => 'Ada', 'count' => '7'],
        );

        $response = $router->dispatch($incoming);

        $this->assertSame('ok', $response->status);
        $this->assertSame('Ada', $response->data['name']);
        $this->assertSame(7, $response->data['count'], 'typed int property must still be coerced from string query value');

        $_GET = [];
    }

    public function test_post_route_resolves_input_from_incoming_body_not_php_input(): void
    {
        $router = new Router();
        $router->post('/echo', EchoInputRoute::class);

        $incoming = new IncomingRequest(
            method: 'POST',
            path: '/echo',
            body: ['name' => 'Grace', 'count' => 3],
        );

        $response = $router->dispatch($incoming);

        $this->assertSame('ok', $response->status);
        $this->assertSame('Grace', $response->data['name']);
        $this->assertSame(3, $response->data['count']);
    }

    public function test_method_is_normalized_to_uppercase(): void
    {
        $router = new Router();
        $router->get('/echo', EchoInputRoute::class);

        // Lowercase method — a test author might type this out of habit.
        $incoming = new IncomingRequest(method: 'get', path: '/echo', query: ['name' => 'lower']);

        $response = $router->dispatch($incoming);

        $this->assertSame('ok', $response->status, 'lowercase method must still match an uppercase-registered route');
        $this->assertSame('lower', $response->data['name']);
    }

    public function test_unmatched_path_returns_404_with_incoming_request(): void
    {
        $router = new Router();
        $router->get('/echo', EchoInputRoute::class);

        $incoming = new IncomingRequest(method: 'GET', path: '/does-not-exist');

        $response = $router->dispatch($incoming);

        $this->assertSame('error', $response->status);
        // NOTE: error404() only sets the status via the global http_response_code()
        // side effect, not on the ApiResponse object itself (unlike the validation
        // 400 path, which does populate ->httpStatusCode) — this asymmetry is a
        // known Tier-1 gap tracked as TESTABILITY-SPEC.md T1-2 (ResponseWriter),
        // not something this test works around by pretending otherwise.
        $this->assertSame(404, http_response_code(), '404 must be set via http_response_code(), even though ApiResponse->httpStatusCode is null for this path');
    }

    public function test_incoming_headers_and_cookies_reach_middleware_via_request_context(): void
    {
        $router = new Router();
        $router->use(new CapturingMiddleware());
        $router->get('/echo', EchoInputRoute::class);

        $incoming = new IncomingRequest(
            method: 'GET',
            path: '/echo',
            headers: ['Authorization' => 'Bearer test-token'],
            cookies: ['refresh_token' => 'test-refresh-cookie'],
        );

        $router->dispatch($incoming);

        $this->assertNotNull(CapturingMiddleware::$lastRequest);
        $this->assertSame('Bearer test-token', CapturingMiddleware::$lastRequest['headers']['Authorization']);
        $this->assertSame('test-refresh-cookie', CapturingMiddleware::$lastRequest['cookies']['refresh_token']);
    }

    public function test_dispatch_without_incoming_request_still_reads_superglobals(): void
    {
        $router = new Router();
        $router->get('/echo', EchoInputRoute::class);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/echo';
        $_GET = ['name' => 'Superglobal', 'count' => '42'];

        $response = $router->dispatch(); // no argument — the untouched default path

        $this->assertSame('ok', $response->status);
        $this->assertSame('Superglobal', $response->data['name']);
        $this->assertSame(42, $response->data['count']);

        $_GET = [];
    }
}
