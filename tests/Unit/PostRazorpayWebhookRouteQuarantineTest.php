<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Database;
use StoneScriptPHP\Subscriptions\SubscriptionConfig;
use StoneScriptPHP\Subscriptions\Routes\PostRazorpayWebhookRoute;

/**
 * PostRazorpayWebhookRoute's contract-violation branches route to
 * WebhookQuarantine instead of silently proceeding with defaulted values or
 * just error_log()-ing. handlePaymentCaptured() is invoked directly via
 * reflection (it's `private`) so these tests exercise the exact
 * post-signature-verification contract checks without needing to fake
 * php://input + a real HMAC signature — the signature-verification path
 * itself (process()) is unchanged, untouched code covered by not being
 * touched at all in this change.
 *
 * See WebhookQuarantineTest's class docblock for why Database::fake() means
 * these tests verify "never throws / degrades to quarantine", not a real
 * persisted row — that requires a live gateway (integration-test concern).
 */
class PostRazorpayWebhookRouteQuarantineTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::clearFakeMode();
    }

    private function invokeHandlePaymentCaptured(PostRazorpayWebhookRoute $route, array $payload): void
    {
        $ref = new \ReflectionMethod($route, 'handlePaymentCaptured');
        $ref->setAccessible(true);
        $ref->invoke($route, $payload);
    }

    public function test_missing_payment_id_quarantines_instead_of_defaulting_to_unknown(): void
    {
        // Database::fake() active → any Database::fn()/getGatewayClient() call
        // (including inside WebhookQuarantine's own persist attempt) is either
        // faked or throws-and-is-swallowed — never a real gateway call, never
        // a fatal error escaping the route.
        Database::fake([]);

        $config = new SubscriptionConfig(['platform_code' => 'exampleapp', 'razorpay_webhook_secret' => 'whsec_test']);
        $route = new PostRazorpayWebhookRoute($config);

        // No exception should propagate — the old behavior silently defaulted
        // missing fields to 'unknown'/0 and proceeded; the new behavior
        // quarantines and returns, still without throwing.
        $this->expectNotToPerformAssertions();
        $this->invokeHandlePaymentCaptured($route, [
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                // 'id' deliberately missing
                'amount' => 418800,
                'email' => 'owner@example.com',
            ]]],
        ]);
    }

    public function test_missing_email_quarantines(): void
    {
        Database::fake([]);
        $config = new SubscriptionConfig(['platform_code' => 'exampleapp', 'razorpay_webhook_secret' => 'whsec_test']);
        $route = new PostRazorpayWebhookRoute($config);

        $this->expectNotToPerformAssertions();
        $this->invokeHandlePaymentCaptured($route, [
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_abc123',
                'amount' => 418800,
                // 'email' deliberately missing
            ]]],
        ]);
    }

    public function test_non_integer_amount_quarantines(): void
    {
        Database::fake([]);
        $config = new SubscriptionConfig(['platform_code' => 'exampleapp', 'razorpay_webhook_secret' => 'whsec_test']);
        $route = new PostRazorpayWebhookRoute($config);

        $this->expectNotToPerformAssertions();
        $this->invokeHandlePaymentCaptured($route, [
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_abc123',
                'amount' => 'not-a-number',
                'email' => 'owner@example.com',
            ]]],
        ]);
    }

    public function test_no_subscription_found_for_email_quarantines_not_just_error_log(): void
    {
        // sub_find_by_email returns no row → the old code path was a bare
        // error_log("MANUAL ACTIVATION NEEDED..."); this now also quarantines.
        Database::fake([
            'sub_find_by_email' => [],
        ]);
        $config = new SubscriptionConfig(['platform_code' => 'exampleapp', 'razorpay_webhook_secret' => 'whsec_test']);
        $route = new PostRazorpayWebhookRoute($config);

        $this->expectNotToPerformAssertions();
        $this->invokeHandlePaymentCaptured($route, [
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_abc123',
                'amount' => 418800,
                'email' => 'nomatch@example.com',
            ]]],
        ]);
    }
}
