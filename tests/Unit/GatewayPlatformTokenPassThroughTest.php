<?php

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Database;
use StoneScriptPHP\Env;

/**
 * The platform token (DB_GATEWAY_PLATFORM_TOKEN) is handed to the gateway client for database calls.
 */
class GatewayPlatformTokenPassThroughTest extends TestCase
{
    private function transportFor(?string $token): object
    {
        $env = (new \ReflectionClass(Env::class))->newInstanceWithoutConstructor();
        $env->DB_GATEWAY_URL = 'http://gateway.invalid:9000';
        $env->DB_GATEWAY_PLATFORM = 'myapp';
        $env->DB_GATEWAY_SCHEMA_NAME = 'main';
        $env->DB_GATEWAY_PLATFORM_TOKEN = $token;

        $db = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($db, 'buildGatewayTransport');
        return $m->invoke($db, $env);
    }

    public function test_token_is_passed_to_the_gateway_client(): void
    {
        $this->assertTrue($this->transportFor('tok-123')->getClient()->hasPlatformToken());
    }

    public function test_no_token_configured_means_none_sent(): void
    {
        $this->assertFalse($this->transportFor(null)->getClient()->hasPlatformToken());
        $this->assertFalse($this->transportFor('')->getClient()->hasPlatformToken());
    }
}
