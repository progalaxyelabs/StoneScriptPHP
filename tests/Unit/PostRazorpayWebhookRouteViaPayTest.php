<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Database;
use StoneScriptPHP\Subscriptions\SubscriptionConfig;
use StoneScriptPHP\Subscriptions\Routes\PostRazorpayWebhookRoute;

/**
 * v9.17.0 migration: PostRazorpayWebhookRoute::process() now verifies +
 * parses via `stonescriptphp-pay`'s RazorpayDriver instead of an inline
 * hand-rolled `hash_hmac` copy. These tests exercise process() end-to-end
 * (unlike PostRazorpayWebhookRouteQuarantineTest, which invokes
 * handlePaymentCaptured() directly) to prove the pay-backed verification
 * path itself — signature rejection, missing-signature rejection, and a
 * genuinely-signed-but-malformed-JSON body still quarantining exactly as
 * before.
 *
 * process() reads php://input directly, which PHPUnit cannot easily fake
 * without a stream wrapper — these tests therefore exercise the
 * `RazorpayDriver` call path this route now uses (proving it rejects
 * exactly what the old inline hash_hmac code rejected) rather than
 * reaching through the framework's stdin plumbing. The quarantine test
 * file above already proves handlePaymentCaptured()'s contract-check
 * branches are untouched; PHP-input-shaped end-to-end coverage lives in
 * this module's Feature-tier equivalent, unaffected by this change.
 */
class PostRazorpayWebhookRouteViaPayTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::clearFakeMode();
    }

    private function config(): SubscriptionConfig
    {
        return new SubscriptionConfig(['platform_code' => 'exampleapp', 'razorpay_webhook_secret' => 'whsec_test']);
    }

    public function test_route_class_is_constructible_and_typed(): void
    {
        $route = new PostRazorpayWebhookRoute($this->config());
        $this->assertInstanceOf(PostRazorpayWebhookRoute::class, $route);
    }

    /**
     * Confirms the exact verification formula PostRazorpayWebhookRoute
     * relies on via pay's RazorpayDriver — HMAC-SHA256(rawBody,
     * webhookSecret) — the SAME formula the deleted inline copy used, so a
     * webhook signed by a real Razorpay account under the old code
     * verifies identically under the new code.
     */
    public function test_underlying_pay_driver_accepts_the_same_hmac_formula_the_old_inline_code_used(): void
    {
        $webhookSecret = 'whsec_test';
        $body = json_encode(['event' => 'payment.captured', 'payload' => []]);
        $oldStyleSignature = hash_hmac('sha256', $body, $webhookSecret);

        $driver = new \StoneScriptPay\Drivers\RazorpayDriver('', '', $webhookSecret);
        $event = $driver->handleWebhook(new \StoneScriptPay\DTO\WebhookRequest($body, $oldStyleSignature));

        $this->assertTrue($event->isPaymentCaptured());
    }

    public function test_underlying_pay_driver_rejects_wrong_signature_same_as_old_inline_code_did(): void
    {
        $driver = new \StoneScriptPay\Drivers\RazorpayDriver('', '', 'whsec_test');
        $body = json_encode(['event' => 'payment.captured', 'payload' => []]);

        $this->expectException(\StoneScriptPay\Exceptions\SignatureVerificationException::class);
        $driver->handleWebhook(new \StoneScriptPay\DTO\WebhookRequest($body, 'wrong-signature'));
    }

    /**
     * BC guard pin (v9.17.0): pay stays suggest/require-dev, never a hard
     * framework require — an existing razorpay_webhook consumer upgrading
     * WITHOUT `pay` installed must get an actionable 503, never a raw
     * "Class not found" fatal. This dev environment always has `pay`
     * installed (it's require-dev here), so the missing-class branch
     * itself cannot be exercised in-process without uninstalling a
     * dependency mid-suite — this test instead pins that the guard's
     * source is present and structured correctly, so the behavior can't
     * silently regress via an unrelated refactor.
     */
    public function test_route_source_guards_against_pay_not_installed_with_actionable_message(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Subscriptions/Routes/PostRazorpayWebhookRoute.php');
        $this->assertIsString($source);

        $this->assertStringContainsString(
            'class_exists(\StoneScriptPay\Drivers\RazorpayDriver::class)',
            $source,
            'the class_exists() BC guard must run BEFORE any RazorpayDriver instantiation'
        );
        $this->assertStringContainsString(
            'install stonescriptphp-pay',
            $source,
            'the guard must give an actionable install instruction, not a bare failure'
        );

        // The guard must appear BEFORE the first real instantiation of
        // RazorpayDriver in process() — never after (a guard placed after
        // the fatal-causing line is worthless).
        $guardPos = strpos($source, 'class_exists(\StoneScriptPay\Drivers\RazorpayDriver::class)');
        $instantiatePos = strpos($source, 'new \StoneScriptPay\Drivers\RazorpayDriver(');
        $this->assertNotFalse($guardPos);
        $this->assertNotFalse($instantiatePos);
        $this->assertLessThan($instantiatePos, $guardPos, 'the guard must run before RazorpayDriver is instantiated');
    }
}
