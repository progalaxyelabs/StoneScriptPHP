<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Attributes\RequiresPermission;
use StoneScriptPHP\Attributes\RequiresRole;
use StoneScriptPHP\Binding\TypedArray;
use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\Routing\MiddlewarePipeline;
use StoneScriptPHP\ApiResponse;

final class TypedArraySweepTestMiddleware implements MiddlewareInterface
{
    public function handle(array $request, callable $next): ?ApiResponse
    {
        return $next($request);
    }
}

/**
 * Coverage for the array→TypedArray sweep's converted internal framework
 * surfaces (as opposed to the DtoHydrator/TypedArray unit-level tests,
 * which live in DtoHydratorTest.php / TypedArrayTest.php).
 */
final class TypedArraySweepTest extends TestCase
{
    public function test_requires_permission_getPermissions_returns_typed_array_of_string(): void
    {
        $attr = new RequiresPermission(['orders.read', 'orders.write']);

        $this->assertInstanceOf(TypedArray::class, $attr->getPermissions());
        $this->assertSame('string', $attr->getPermissions()->type());
        $this->assertSame(['orders.read', 'orders.write'], $attr->getPermissions()->all());
    }

    public function test_requires_permission_accepts_single_string_back_compat(): void
    {
        $attr = new RequiresPermission('orders.read');
        $this->assertSame(['orders.read'], $attr->getPermissions()->all());
    }

    public function test_requires_permission_accepts_typed_array_directly(): void
    {
        $attr = new RequiresPermission(new TypedArray('string', ['a', 'b']));
        $this->assertSame(['a', 'b'], $attr->getPermissions()->all());
    }

    public function test_requires_role_getRoles_returns_typed_array_of_string(): void
    {
        $attr = new RequiresRole(['admin', 'owner']);

        $this->assertInstanceOf(TypedArray::class, $attr->getRoles());
        $this->assertSame(['admin', 'owner'], $attr->getRoles()->all());
    }

    public function test_middleware_pipeline_getMiddleware_returns_typed_array_of_middleware_interface(): void
    {
        $pipeline = new MiddlewarePipeline();
        $mw1 = new TypedArraySweepTestMiddleware();
        $mw2 = new TypedArraySweepTestMiddleware();
        $pipeline->pipe($mw1)->pipe($mw2);

        $result = $pipeline->getMiddleware();

        $this->assertInstanceOf(TypedArray::class, $result);
        $this->assertSame(MiddlewareInterface::class, $result->type());
        $this->assertSame(2, $result->count());
        $this->assertSame($mw1, $result->first());
        $this->assertSame($mw2, $result->last());
    }

    public function test_router_getGlobalMiddleware_returns_typed_array(): void
    {
        $router = new \StoneScriptPHP\Routing\Router();
        $mw = new TypedArraySweepTestMiddleware();
        $router->use($mw);

        $result = $router->getGlobalMiddleware();

        $this->assertInstanceOf(TypedArray::class, $result);
        $this->assertSame(MiddlewareInterface::class, $result->type());
        $this->assertSame(1, $result->count());
    }
}
