<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Database;
use StoneScriptPHP\Webhooks\WebhookQuarantine;

/**
 * WebhookQuarantine (v9.6.0, Phase 4) — the persist-raw + alert + quarantine
 * safety net for inbound webhooks whose payload fails its typed contract
 * after signature verification.
 *
 * Database::getGatewayClient() throws when Database::fake() is active (no
 * real GatewayClient in fake mode — see Database::getGatewayClient()'s own
 * docblock), so quarantine()'s try/catch around the persist step is exactly
 * what's exercised under Database::fake(): it MUST NOT let that exception
 * escape (this is the "never throws, last line of defense" contract this
 * module exists to guarantee for a payment-path caller). Full live-gateway
 * persistence is an integration-test concern, not a unit-test one — same
 * limitation every other `getGatewayClient()`-using route in this framework
 * already has (see SubscriptionMiddlewareTest's docblock for the same note).
 */
class WebhookQuarantineTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::clearFakeMode();
    }

    public function test_quarantine_never_throws_even_when_persist_is_impossible(): void
    {
        // Database::fake() active → getGatewayClient() throws internally;
        // quarantine() must swallow it, not propagate.
        Database::fake([]);

        $this->expectNotToPerformAssertions();
        WebhookQuarantine::quarantine(
            'exampleapp',
            'razorpay',
            'payment.captured',
            'test: entity missing id',
            ['X-Razorpay-Signature' => 'abc123', 'Content-Type' => 'application/json'],
            ['event' => 'payment.captured', 'payload' => []]
        );
    }

    public function test_get_schema_files_returns_files_that_exist_on_disk(): void
    {
        $files = WebhookQuarantine::getSchemaFiles();

        $this->assertArrayHasKey('tables', $files);
        $this->assertArrayHasKey('functions', $files);
        $this->assertNotEmpty($files['tables']);
        $this->assertNotEmpty($files['functions']);

        foreach ([...$files['tables']->all(), ...$files['functions']->all()] as $path) {
            $this->assertFileExists($path, "Schema file declared by getSchemaFiles() must exist: $path");
        }
    }

    public function test_schema_table_is_idempotent_create_if_not_exists(): void
    {
        $sql = file_get_contents(WebhookQuarantine::getSchemaFiles()['tables'][0]);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', $sql);
        $this->assertStringNotContainsString('DROP TABLE', $sql);
        $this->assertStringNotContainsString('TRUNCATE', $sql);
    }
}
