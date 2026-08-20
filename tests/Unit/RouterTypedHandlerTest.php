<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\ITypedRouteHandler;
use StoneScriptPHP\Routing\Router;

final class RouterTypedHandlerTestRequest
{
    public function __construct(
        public readonly int $distributor_id,
        public readonly string $invoice_number,
    ) {
    }
}

final class RouterTypedHandlerTestResponse
{
    public function __construct(
        public readonly int $invoice_id,
        public readonly string $message,
    ) {
    }
}

/** Marker-interface-only typed handler — no process(), no validation_rules(). */
final class RouterTypedHandlerTestRoute implements ITypedRouteHandler
{
    public function execute(RouterTypedHandlerTestRequest $request): RouterTypedHandlerTestResponse
    {
        return new RouterTypedHandlerTestResponse(
            invoice_id: 999,
            message: "saved {$request->invoice_number} for distributor {$request->distributor_id}",
        );
    }
}

final class RouterTypedHandlerMismatchedRoute implements ITypedRouteHandler
{
    public function execute(RouterTypedHandlerTestRequest $request): RouterTypedHandlerTestResponse
    {
        return new RouterTypedHandlerTestResponse(1, 'unused');
    }
}

/**
 * End-to-end regression guard for the ITypedRouteHandler dispatch path in
 * Router::executeHandler() — see SPEC-typed-request-binder.md §5.
 */
final class RouterTypedHandlerTest extends TestCase
{
    private function exec(object $handler, array $input, ?string $declaredRequestClass = null): ApiResponse
    {
        $router = new Router();
        $ref = new \ReflectionClass($router);
        $method = $ref->getMethod('executeHandler');
        $method->setAccessible(true);
        $request = ['input' => $input, 'params' => []];
        if ($declaredRequestClass !== null) {
            $request['route'] = ['request' => $declaredRequestClass];
        }
        return $method->invoke($router, $handler, $request);
    }

    public function test_typed_handler_success_hydrates_and_wraps_response(): void
    {
        $resp = $this->exec(new RouterTypedHandlerTestRoute(), [
            'distributor_id' => '5', // numeric string on the wire — exactly the real bug shape
            'invoice_number' => 'INV-1',
        ]);

        $this->assertSame('ok', $resp->status);
        $this->assertSame('saved INV-1 for distributor 5', $resp->message);
        $this->assertSame(999, $resp->data['invoice_id']);
        $this->assertArrayNotHasKey('message', $resp->data, 'message must be promoted out of data, not duplicated');
    }

    public function test_typed_handler_missing_required_field_returns_400_not_500(): void
    {
        $resp = $this->exec(new RouterTypedHandlerTestRoute(), [
            'invoice_number' => 'INV-1',
            // distributor_id missing entirely
        ]);

        $this->assertSame('error', $resp->status);
        $this->assertSame(400, $resp->httpStatusCode);
        $this->assertNotNull($resp->errors);
        $this->assertSame('distributor_id', $resp->errors[0]['field']);
    }

    public function test_typed_handler_non_numeric_id_returns_400_not_500(): void
    {
        // This is exactly the shape of the live bug this framework feature was
        // built to fix: a hostile/malformed distributor_id must never TypeError
        // through to a 500.
        $resp = $this->exec(new RouterTypedHandlerTestRoute(), [
            'distributor_id' => 'not-an-id',
            'invoice_number' => 'INV-1',
        ]);

        $this->assertSame('error', $resp->status);
        $this->assertSame(400, $resp->httpStatusCode);
    }

    public function test_typed_handler_request_dto_class_mismatch_with_route_meta_is_a_dev_error_not_a_silent_bind(): void
    {
        // executeHandler()'s outer catch(\Exception) — the same safety net every
        // route gets — turns the LogicException into a generic 500 rather than
        // leaking it to the client, exactly like any other framework-internal
        // misconfiguration. The important assertion is that it does NOT proceed
        // to hydrate/execute with the mismatched class silently.
        $resp = $this->exec(
            new RouterTypedHandlerMismatchedRoute(),
            ['distributor_id' => 1, 'invoice_number' => 'x'],
            'SomeOther\\Namespace\\WrongRequestClass'
        );
        $this->assertSame('error', $resp->status);
    }

    public function test_typed_handler_request_dto_class_matching_route_meta_passes(): void
    {
        $resp = $this->exec(
            new RouterTypedHandlerTestRoute(),
            ['distributor_id' => 1, 'invoice_number' => 'x'],
            RouterTypedHandlerTestRequest::class
        );
        $this->assertSame('ok', $resp->status);
    }
}
