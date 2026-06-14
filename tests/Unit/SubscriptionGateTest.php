<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use StoneScriptPHP\Application;

/**
 * Tests the subscription wiring gate.
 *
 * Application::run() registers SubscriptionMiddleware + subscription routes +
 * subscription public JWT-excluded paths only when subscriptions are enabled.
 * Before the fix the gate was `!empty($subscriptionConfig)`, so a platform that
 * explicitly set ['enabled' => false] (a non-empty array) STILL got the
 * middleware wired — which then queried an uninstalled sub_* schema.
 *
 * The decision now lives in the pure predicate Application::isSubscriptionEnabled()
 * (private static) used at all three gate sites in run(). We exercise it directly
 * via reflection so we assert the real decision logic, not a copy of it.
 */
final class SubscriptionGateTest extends TestCase
{
    private function gate(array $subscriptionConfig): bool
    {
        $m = new ReflectionMethod(Application::class, 'isSubscriptionEnabled');
        $m->setAccessible(true);
        return $m->invoke(null, $subscriptionConfig);
    }

    /** (a) Explicitly disabled → subscription wiring is SKIPPED. */
    public function testEnabledFalseSkipsWiring(): void
    {
        $this->assertFalse($this->gate(['enabled' => false]));
        // Disabled even when other config keys are present.
        $this->assertFalse($this->gate(['enabled' => false, 'prefix' => '/subscription']));
    }

    /** (b) Explicitly enabled → subscription wiring is registered. */
    public function testEnabledTrueWiresIt(): void
    {
        $this->assertTrue($this->gate(['enabled' => true]));
        $this->assertTrue($this->gate(['enabled' => true, 'prefix' => '/subscription']));
    }

    /** (c) Back-compat: config present but NO 'enabled' key → stays ON. */
    public function testNoEnabledKeyStaysOnForBackCompat(): void
    {
        $this->assertTrue($this->gate(['prefix' => '/subscription']));
        $this->assertTrue($this->gate(['plans' => ['free', 'pro']]));
    }

    /** Absent/empty subscription config → no wiring (unchanged behaviour). */
    public function testEmptyConfigSkipsWiring(): void
    {
        $this->assertFalse($this->gate([]));
    }

    /**
     * Truthy/falsy coercion under the `(... ?? true)` + boolean-AND contract:
     *  - 0 and '' are falsy but NOT null, so `?? true` does NOT fire → gate OFF.
     *  - null DOES trigger `?? true` → gate ON (treated as "no explicit value").
     *  - 1 is truthy → gate ON.
     */
    public function testEnabledFlagCoercion(): void
    {
        $this->assertFalse($this->gate(['enabled' => 0]));
        $this->assertFalse($this->gate(['enabled' => '']));
        $this->assertTrue($this->gate(['enabled' => null]));
        $this->assertTrue($this->gate(['enabled' => 1]));
    }
}
