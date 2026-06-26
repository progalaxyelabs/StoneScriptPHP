<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\IRouteHandler;

/**
 * Regression guard for #3055: the active runtime Router
 * (StoneScriptPHP\Routing\Router) must honor a handler's declared
 * validation_rules() and reject missing/invalid required fields with a clean
 * 400 AT THE EDGE — before the handler runs and before NULLs reach the SQL
 * functions (where they surfaced as 500s). Prior to the fix, executeHandler()
 * populated properties and called process() without ever invoking
 * validation_rules(), so declared validation was dead code fleet-wide.
 */

/** Mimics the medstoreapp dropdown route: p_dropdown_type is required. */
class StubDropdownRoute implements IRouteHandler
{
    public string $p_dropdown_type = '';
    public function validation_rules(): array { return ['p_dropdown_type' => 'required']; }
    public function process(): ApiResponse
    {
        return new ApiResponse('ok', 'dropdown ok', ['type' => $this->p_dropdown_type]);
    }
}

/** A second, unrelated route to prove the fix is router-wide, not route-specific. */
class StubReportRoute implements IRouteHandler
{
    public string $customer_id = '';
    public function validation_rules(): array { return ['customer_id' => 'required']; }
    public function process(): ApiResponse { return new ApiResponse('ok', 'report ok'); }
}

class RouterValidationTest extends TestCase
{
    /** Drive the private executeHandler() the way dispatch() does. */
    private function exec(IRouteHandler $handler, array $input): ApiResponse
    {
        $router = new Router();
        $ref = new \ReflectionClass($router);
        $method = $ref->getMethod('executeHandler');
        $method->setAccessible(true);
        return $method->invoke($router, $handler, ['input' => $input, 'params' => []]);
    }

    public function test_missing_required_field_returns_400_on_dropdown_route(): void
    {
        $resp = $this->exec(new StubDropdownRoute(), []); // p_dropdown_type missing
        $this->assertSame('error', $resp->status);
        $this->assertSame('Validation failed', $resp->message);
        $this->assertSame(400, $resp->httpStatusCode, 'missing required field must 400 at the edge');
    }

    public function test_missing_required_field_returns_400_on_second_route(): void
    {
        $resp = $this->exec(new StubReportRoute(), []); // customer_id missing
        $this->assertSame('error', $resp->status);
        $this->assertSame('Validation failed', $resp->message);
        $this->assertSame(400, $resp->httpStatusCode);
    }

    public function test_valid_input_passes_validation_and_reaches_handler(): void
    {
        $resp = $this->exec(new StubDropdownRoute(), ['p_dropdown_type' => 'states']);
        $this->assertSame('ok', $resp->status);
        $this->assertSame('dropdown ok', $resp->message);
        $this->assertSame('states', $resp->data['type']);
    }
}
