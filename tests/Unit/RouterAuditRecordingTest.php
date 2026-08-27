<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Audit\AuditRecorder;
use StoneScriptPHP\Audit\HasAuditBag;
use StoneScriptPHP\Env;
use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ITypedRouteHandler;
use StoneScriptPHP\Routing\Router;

/** Legacy IRouteHandler path, enriches the bag. */
final class RouterAuditLegacyRoute implements IRouteHandler
{
    use HasAuditBag;

    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        $this->auditRecord(entityType: 'widget', entityId: '7', summary: 'created widget 7');
        return new ApiResponse('ok', '', ['id' => 7], 201);
    }
}

/** Legacy IRouteHandler path, never touches the bag at all. */
final class RouterAuditLegacyRouteNoBag implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        return new ApiResponse('ok', '', [], 200);
    }
}

final class RouterAuditTypedRequest
{
    public function __construct(public readonly int $id)
    {
    }
}

final class RouterAuditTypedResponse
{
    public function __construct(public readonly string $message)
    {
    }
}

/** Typed-handler path. */
final class RouterAuditTypedRoute implements ITypedRouteHandler
{
    use HasAuditBag;

    public function execute(RouterAuditTypedRequest $request): RouterAuditTypedResponse
    {
        $this->auditRecord(entityType: 'widget', entityId: (string) $request->id, action: 'widget.delete');
        return new RouterAuditTypedResponse('deleted');
    }
}

/**
 * End-to-end regression guard: Router::executeHandler() must call
 * AuditRecorder::record() after a successful process()/execute() — for BOTH
 * dispatch paths (legacy IRouteHandler and ITypedRouteHandler) — and must
 * NEVER call it for a non-mutating (GET) request.
 */
final class RouterAuditRecordingTest extends TestCase
{
    /** @var string[] */
    private array $putenvKeys = [];

    protected function tearDown(): void
    {
        AuditRecorder::fakeCallFunction(null);
        foreach ($this->putenvKeys as $key) {
            putenv($key);
        }
        $this->putenvKeys = [];
        $prop = new \ReflectionProperty(Env::class, '_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
        parent::tearDown();
    }

    private function enableAuditTrail(): void
    {
        foreach ([
            'AUDIT_TRAIL_ENABLED' => 'true',
            'DB_GATEWAY_URL' => 'http://gateway.test:9000',
            'DB_GATEWAY_PLATFORM' => 'testplatform',
            'DB_GATEWAY_SCHEMA_NAME' => 'main',
            'PLATFORM_CODE' => 'testplatform',
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $this->putenvKeys[] = $key;
        }
        $prop = new \ReflectionProperty(Env::class, '_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function exec(object $handler, array $request): ApiResponse
    {
        $router = new Router();
        $ref = new \ReflectionClass($router);
        $method = $ref->getMethod('executeHandler');
        $method->setAccessible(true);
        return $method->invoke($router, $handler, $request);
    }

    public function test_legacy_handler_with_bag_reaches_audit_recorder(): void
    {
        $this->enableAuditTrail();
        $captured = null;
        AuditRecorder::fakeCallFunction(function (array $params) use (&$captured) {
            $captured = $params;
        });

        $this->exec(new RouterAuditLegacyRoute(), [
            'method' => 'POST',
            'path' => '/widgets',
            'route' => ['pattern' => '/widgets'],
            'input' => [],
            'params' => [],
        ]);

        $this->assertNotNull($captured, 'Router must call AuditRecorder::record() after a successful legacy process()');
        $this->assertSame('widget', $captured[7]);
        $this->assertSame('7', $captured[8]);
        $this->assertSame('created widget 7', $captured[11]);
    }

    public function test_legacy_handler_without_bag_still_writes_base_record(): void
    {
        $this->enableAuditTrail();
        $captured = null;
        AuditRecorder::fakeCallFunction(function (array $params) use (&$captured) {
            $captured = $params;
        });

        $this->exec(new RouterAuditLegacyRouteNoBag(), [
            'method' => 'POST',
            'path' => '/widgets',
            'route' => ['pattern' => '/widgets'],
            'input' => [],
            'params' => [],
        ]);

        $this->assertNotNull($captured, 'a handler that never uses HasAuditBag must still get the base record floor');
        $this->assertNull($captured[7], 'entity_type must be null with no bag');
    }

    public function test_typed_handler_reaches_audit_recorder(): void
    {
        $this->enableAuditTrail();
        $captured = null;
        AuditRecorder::fakeCallFunction(function (array $params) use (&$captured) {
            $captured = $params;
        });

        $this->exec(new RouterAuditTypedRoute(), [
            'method' => 'DELETE',
            'path' => '/widgets/7',
            'route' => ['pattern' => '/widgets/{id}'],
            'input' => ['id' => 7],
            'params' => [],
        ]);

        $this->assertNotNull($captured, 'Router must call AuditRecorder::record() after a successful typed execute() too');
        $this->assertSame('widget.delete', $captured[5]);
        $this->assertSame('7', $captured[8]);
    }

    public function test_get_never_reaches_audit_recorder_even_with_a_bag_capable_handler(): void
    {
        $this->enableAuditTrail();
        $called = false;
        AuditRecorder::fakeCallFunction(function (array $params) use (&$called) {
            $called = true;
        });

        $this->exec(new RouterAuditLegacyRoute(), [
            'method' => 'GET',
            'path' => '/widgets',
            'route' => ['pattern' => '/widgets'],
            'input' => [],
            'params' => [],
        ]);

        $this->assertFalse($called, 'GET must never produce an audit call, regardless of handler capability');
    }
}
