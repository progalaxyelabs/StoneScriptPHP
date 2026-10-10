<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Auth\AuthenticatedUser;
use StoneScriptPHP\HtmlResponse;
use StoneScriptPHP\Http\ResponseEmitter;
use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\RedirectResponse;
use StoneScriptPHP\Routing\IncomingRequest;
use StoneScriptPHP\Routing\Middleware\CorsMiddleware;
use StoneScriptPHP\Routing\Middleware\CsrfMiddleware;
use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Subscriptions\SubscriptionMiddleware;

final class HeadProbeRoute implements IRouteHandler
{
    public static int $calls = 0;
    public ?string $q = null;

    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        self::$calls++;
        return new ApiResponse('ok', 'healthy', ['q' => $this->q]);
    }
}

final class StreamingProbeRoute implements IRouteHandler, \StoneScriptPHP\IStreamingRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        HeadProbeRoute::$calls++;
        return new ApiResponse('ok', 'stream');
    }
}

final class MethodSpyMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    public static array $seen = [];

    public function handle(array $request, callable $next): ?ApiResponse
    {
        self::$seen[] = (string) $request['method'];
        return $next($request);
    }
}

/**
 * L17: the framework answers HEAD wherever GET is routed (RFC 9110 9.3.2) and
 * never treats it as a write; OPTIONS/405 carry a correct Allow header.
 */
final class RouterHeadOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        HeadProbeRoute::$calls = 0;
        MethodSpyMiddleware::$seen = [];
        AuthContext::clear();
    }

    protected function tearDown(): void
    {
        AuthContext::clear();
        http_response_code(200);
    }

    private function router(): Router
    {
        $r = new Router();
        $r->get('/health', HeadProbeRoute::class, isPublic: true);
        $r->post('/things', HeadProbeRoute::class, isPublic: true);
        return $r;
    }

    public function test_head_is_answered_by_the_get_route_with_get_status(): void
    {
        $router = $this->router();
        $get = $router->dispatch(new IncomingRequest('GET', '/health'));
        $head = $router->dispatch(new IncomingRequest('HEAD', '/health'));

        $this->assertSame('ok', $head->status);
        $this->assertSame($get->toJson(), $head->toJson(), 'same response object content as GET');
        $this->assertSame(2, HeadProbeRoute::$calls);
    }

    public function test_head_binds_query_input_like_get(): void
    {
        $router = $this->router();
        $head = $router->dispatch(new IncomingRequest('HEAD', '/health', query: ['q' => 'x']));
        $this->assertSame(['q' => 'x'], $head->data);
    }

    public function test_head_is_case_insensitive(): void
    {
        $head = $this->router()->dispatch(new IncomingRequest('head', '/health'));
        $this->assertSame('ok', $head->status);
    }

    public function test_head_on_path_with_only_post_is_405_with_allow(): void
    {
        $res = $this->router()->dispatch(new IncomingRequest('HEAD', '/things'));
        $this->assertSame(405, $res->httpStatusCode);
        $this->assertSame('OPTIONS, POST', $res->headers['Allow']);
    }

    public function test_wrong_method_on_known_path_is_405_and_unknown_path_stays_404(): void
    {
        $router = $this->router();
        $res = $router->dispatch(new IncomingRequest('DELETE', '/health'));
        $this->assertSame(405, $res->httpStatusCode);
        $this->assertSame('GET, HEAD, OPTIONS', $res->headers['Allow']);

        $this->assertSame('Not found', $router->dispatch(new IncomingRequest('HEAD', '/nope'))->message);
    }

    public function test_explicit_head_route_wins_over_get_fallback(): void
    {
        $router = $this->router();
        $router->addRoute('HEAD', '/health', HeadProbeRoute::class, isPublic: true);
        $res = $router->dispatch(new IncomingRequest('HEAD', '/health'));
        $this->assertSame('ok', $res->status);
    }

    public function test_head_request_method_reaches_middleware_as_head(): void
    {
        $router = $this->router();
        $router->use(new MethodSpyMiddleware());
        $router->dispatch(new IncomingRequest('HEAD', '/health'));
        $this->assertSame(['HEAD'], MethodSpyMiddleware::$seen);
    }

    public function test_route_level_middleware_registered_on_get_runs_for_head(): void
    {
        $router = new Router();
        $router->get('/health', HeadProbeRoute::class, [new MethodSpyMiddleware()], isPublic: true);
        $router->dispatch(new IncomingRequest('HEAD', '/health'));
        $this->assertSame(['HEAD'], MethodSpyMiddleware::$seen);
    }

    public function test_protected_get_route_still_requires_auth_for_head(): void
    {
        // JwtAuthMiddleware gates on route meta; here an auth-style guard proves HEAD is not an auth bypass.
        $router = new Router();
        $router->get('/secret', HeadProbeRoute::class);
        $router->use(new class implements MiddlewareInterface {
            public function handle(array $request, callable $next): ?ApiResponse
            {
                if (($request['route']['is_public'] ?? true) === false) {
                    return new ApiResponse('error', 'unauthorized', null, 401);
                }
                return $next($request);
            }
        });
        $this->assertSame(401, $router->dispatch(new IncomingRequest('HEAD', '/secret'))->httpStatusCode);
        $this->assertSame(0, HeadProbeRoute::$calls);
    }

    public function test_csrf_middleware_does_not_treat_head_as_a_write(): void
    {
        $router = $this->router();
        $router->use(new CsrfMiddleware(['/health']));
        $res = $router->dispatch(new IncomingRequest('HEAD', '/health'));
        $this->assertSame('ok', $res->status);
    }

    public function test_read_only_subscription_gating_lets_head_through_and_blocks_writes(): void
    {
        $mw = new class (
            expiredMode: 'read_only',
            exemptPaths: null,
            writeAllowList: [],
            warningDays: 7,
            missingSubscription: 'read_only',
            statusProvider: fn(string $t): array => ['status' => 'active', 'is_trial' => false, 'is_active' => false, 'expires_at' => '2020-01-01T00:00:00Z'],
            clock: fn() => new \DateTimeImmutable('2026-10-01T00:00:00Z'),
        ) extends SubscriptionMiddleware {
            protected function sendHeader(string $name, string $value): void
            {
            }
        };
        AuthContext::setUser(new AuthenticatedUser(user_id: 'u1', tenant_id: 't1'));
        $_SERVER['REQUEST_URI'] = '/health';

        $router = $this->router();
        $router->use($mw);

        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $this->assertSame('ok', $router->dispatch(new IncomingRequest('HEAD', '/health'))->status);

        $_SERVER['REQUEST_URI'] = '/things';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertSame(423, $router->dispatch(new IncomingRequest('POST', '/things'))->httpStatusCode);
        unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
    }

    public function test_streaming_route_head_does_not_run_the_handler(): void
    {
        $router = new Router();
        $router->get('/events', HeadProbeRoute::class, isPublic: true, streaming: true);
        $res = $router->dispatch(new IncomingRequest('HEAD', '/events'));
        $this->assertSame(0, HeadProbeRoute::$calls);
        $this->assertSame('text/event-stream', $res->headers['Content-Type']);

        $router->dispatch(new IncomingRequest('GET', '/events'));
        $this->assertSame(1, HeadProbeRoute::$calls, 'GET still runs the handler');
    }

    public function test_options_on_known_path_without_cors_layer_is_204_with_allow(): void
    {
        $res = $this->router()->dispatch(new IncomingRequest('OPTIONS', '/health'));
        $this->assertSame(204, $res->httpStatusCode);
        $this->assertSame('GET, HEAD, OPTIONS', $res->headers['Allow']);
    }

    public function test_options_on_unknown_path_is_404(): void
    {
        $this->assertSame('Not found', $this->router()->dispatch(new IncomingRequest('OPTIONS', '/nope'))->message);
    }

    public function test_cors_preflight_is_204_and_advertises_allow_only_to_an_allowed_origin(): void
    {
        $router = $this->router();
        $router->use(new CorsMiddleware(['https://app.example']));
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';

        $_SERVER['HTTP_ORIGIN'] = 'https://app.example';
        $res = $router->dispatch(new IncomingRequest('OPTIONS', '/things'));
        $this->assertSame(204, $res->httpStatusCode);
        $this->assertSame('OPTIONS, POST', $res->headers['Allow']);
        $this->assertSame('', ResponseEmitter::render($res, 'OPTIONS')['body'], '204 carries no body');

        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
        $res = $router->dispatch(new IncomingRequest('OPTIONS', '/things'));
        $this->assertArrayNotHasKey('Allow', $res->headers, 'an unlisted origin learns nothing about the route table');

        unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_ORIGIN']);
    }

    // ---- HEAD is safe by construction (probe mode) ----

    public function test_route_flagged_head_probe_does_not_run_the_handler(): void
    {
        $router = new Router();
        $router->get('/oauth/cb', HeadProbeRoute::class, isPublic: true, head: 'probe');
        $res = $router->dispatch(new IncomingRequest('HEAD', '/oauth/cb'));
        $this->assertSame(0, HeadProbeRoute::$calls);
        $this->assertTrue($res->headProbe);
        $this->assertSame('ok', $res->status);

        $router->dispatch(new IncomingRequest('GET', '/oauth/cb'));
        $this->assertSame(1, HeadProbeRoute::$calls, 'GET still runs');
    }

    public function test_probe_has_no_content_length_and_streaming_probe_is_event_stream(): void
    {
        $router = new Router();
        $router->get('/p', HeadProbeRoute::class, isPublic: true, head: 'probe');
        $router->get('/events', HeadProbeRoute::class, isPublic: true, streaming: true);

        $p = ResponseEmitter::render($router->dispatch(new IncomingRequest('HEAD', '/p')), 'HEAD');
        $this->assertArrayNotHasKey('Content-Length', $p['headers']);
        $this->assertSame('', $p['body']);

        $e = ResponseEmitter::render($router->dispatch(new IncomingRequest('HEAD', '/events')), 'HEAD');
        $this->assertSame('text/event-stream', $e['headers']['Content-Type']);
        $this->assertArrayNotHasKey('Content-Length', $e['headers']);
    }

    public function test_streaming_interface_handler_is_probed_without_the_streaming_flag(): void
    {
        $router = new Router();
        $router->get('/sse', StreamingProbeRoute::class, isPublic: true); // no streaming: true
        $res = $router->dispatch(new IncomingRequest('HEAD', '/sse'));
        $this->assertSame(0, HeadProbeRoute::$calls);
        $this->assertSame('text/event-stream', $res->headers['Content-Type']);
    }

    public function test_global_head_executes_get_false_probes_everything_unless_route_opts_in(): void
    {
        $router = $this->router()->setHeadExecutesGet(false);
        $router->get('/safe', HeadProbeRoute::class, isPublic: true, head: 'execute');

        $router->dispatch(new IncomingRequest('HEAD', '/health'));
        $this->assertSame(0, HeadProbeRoute::$calls, 'global probe default');

        $router->dispatch(new IncomingRequest('HEAD', '/safe'));
        $this->assertSame(1, HeadProbeRoute::$calls, "route opted back in with head: 'execute'");
    }

    public function test_default_head_executes_the_get_handler(): void
    {
        $this->router()->dispatch(new IncomingRequest('HEAD', '/health'));
        $this->assertSame(1, HeadProbeRoute::$calls);
    }

    public function test_invalid_head_value_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Router::normalizeRouteConfig(['handler' => HeadProbeRoute::class, 'head' => 'sometimes']);
    }

    public function test_routes_php_head_key_is_honoured(): void
    {
        $router = new Router();
        $router->loadRoutes(['GET' => ['/magic' => ['handler' => HeadProbeRoute::class, 'is_public' => true, 'head' => 'probe']]]);
        $router->dispatch(new IncomingRequest('HEAD', '/magic'));
        $this->assertSame(0, HeadProbeRoute::$calls);
    }

    public function test_builtin_oauth_routes_are_head_probe(): void
    {
        $router = new Router();
        \StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthRoutes::register($router, [
            'client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'https://x/cb',
            'user_resolver' => new class implements \StoneScriptPHP\Auth\BuiltinOAuth\GoogleOAuthUserResolver {
                public function resolve(array $profile): array
                {
                    return [];
                }
            },
        ]);
        foreach (['/oauth/google', '/oauth/google/callback'] as $path) {
            $res = $router->dispatch(new IncomingRequest('HEAD', $path));
            $this->assertTrue($res->headProbe, "$path must be probed (callback mints tokens, initiate mints state)");
        }
    }

    public function test_regex_is_compiled_once_per_pattern(): void
    {
        $router = new Router();
        $router->get('/a/{id}', HeadProbeRoute::class, isPublic: true);
        for ($i = 0; $i < 3; $i++) {
            $router->dispatch(new IncomingRequest('GET', '/a/' . $i));
            $router->dispatch(new IncomingRequest('POST', '/a/' . $i)); // 405 path -> allowedMethodsFor
        }
        $cache = (new \ReflectionProperty($router, 'regexCache'))->getValue($router);
        $this->assertCount(1, $cache);
    }

    // ---- ResponseEmitter (pure rendering) ----

    public function test_head_render_has_get_headers_and_content_length_but_no_body(): void
    {
        $r = new ApiResponse('ok', 'healthy', ['a' => 1], 200);
        $get = ResponseEmitter::render($r, 'GET');
        $head = ResponseEmitter::render($r, 'HEAD');

        $this->assertNotSame('', $get['body']);
        $this->assertSame('', $head['body']);
        $this->assertSame(200, $head['status']);
        $this->assertSame((string) strlen($get['body']), $head['headers']['Content-Length']);
        $this->assertSame('application/json', $head['headers']['Content-Type']);
    }

    public function test_head_render_of_error_keeps_status(): void
    {
        $head = ResponseEmitter::render(new ApiResponse('error', 'nope', null, 404), 'HEAD');
        $this->assertSame(404, $head['status']);
        $this->assertSame('', $head['body']);
    }

    public function test_html_and_redirect_render(): void
    {
        $html = ResponseEmitter::render(new HtmlResponse('<p>hi</p>'), 'HEAD');
        $this->assertSame('text/html; charset=utf-8', $html['headers']['Content-Type']);
        $this->assertSame('9', $html['headers']['Content-Length']);

        $redir = ResponseEmitter::render(new RedirectResponse('/x', 302), 'GET');
        $this->assertSame('/x', $redir['headers']['Location']);
        $this->assertSame('', $redir['body']);
        $this->assertSame(302, $redir['status']);
    }

    public function test_204_and_304_never_carry_a_body(): void
    {
        foreach ([204, 304] as $code) {
            $out = ResponseEmitter::render(new ApiResponse('ok', '', null, $code), 'GET');
            $this->assertSame('', $out['body']);
            $this->assertArrayNotHasKey('Content-Length', $out['headers']);
        }
    }

    public function test_response_headers_override_defaults_case_insensitively(): void
    {
        $r = new ApiResponse('ok', '', null, 200);
        $r->headers = ['content-type' => 'text/event-stream'];
        $out = ResponseEmitter::render($r, 'HEAD');
        $this->assertSame('text/event-stream', $out['headers']['content-type']);
        $this->assertArrayNotHasKey('Content-Type', $out['headers']);
    }
}
