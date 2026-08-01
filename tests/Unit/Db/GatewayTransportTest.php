<?php

declare(strict_types=1);

namespace Tests\Unit\Db;

use PHPUnit\Framework\TestCase;
use StoneScriptDB\GatewayClient;
use StoneScriptDB\GatewayException;
use StoneScriptPHP\Db\GatewayTransport;

/**
 * Unit tests for DB_MODE=gateway's DbTransport implementation.
 *
 * GatewayTransport is a thin, deliberately-transparent wrapper around the
 * existing GatewayClient — these tests guard that it stays that way:
 * callFunction()/isConnected() must delegate byte-for-byte and
 * GatewayException must propagate UNCAUGHT (not wrapped/translated here —
 * that stays Database::_fn()'s job, unchanged, so gateway mode's behavior
 * is byte-identical to before the DbTransport refactor).
 */
class GatewayTransportTest extends TestCase
{
    public function test_callFunction_delegates_to_gateway_client(): void
    {
        $client = $this->createMock(GatewayClient::class);
        $client->expects($this->once())
            ->method('callFunction')
            ->with('get_user', [1])
            ->willReturn([['id' => 1, 'name' => 'Ada']]);

        $transport = new GatewayTransport($client);
        $rows = $transport->callFunction('get_user', [1]);

        $this->assertSame([['id' => 1, 'name' => 'Ada']], $rows);
    }

    public function test_isConnected_delegates_to_gateway_client(): void
    {
        $client = $this->createMock(GatewayClient::class);
        $client->method('isConnected')->willReturn(true);

        $transport = new GatewayTransport($client);

        $this->assertTrue($transport->isConnected());
    }

    public function test_gateway_exception_propagates_unwrapped(): void
    {
        $client = $this->createMock(GatewayClient::class);
        $client->method('callFunction')->willThrowException(
            new GatewayException('connection_failed', 503, null, ['error' => 'connection_failed'])
        );

        $transport = new GatewayTransport($client);

        $this->expectException(GatewayException::class);
        $transport->callFunction('get_dashboard', []);
    }

    public function test_getClient_returns_the_wrapped_client(): void
    {
        $client = $this->createMock(GatewayClient::class);
        $transport = new GatewayTransport($client);

        $this->assertSame($client, $transport->getClient());
    }
}
