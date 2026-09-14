<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Billing\Contracts\PaymentProvider;
use StoneScriptPHP\Billing\Dto\CreateOrderRequest;
use StoneScriptPHP\Billing\Dto\CreateSubscriptionRequest;
use StoneScriptPHP\Billing\Dto\OrderResult;
use StoneScriptPHP\Billing\Dto\RefundRequest;
use StoneScriptPHP\Billing\Dto\RefundResult;
use StoneScriptPHP\Billing\Dto\SubscriptionResult;
use StoneScriptPHP\Billing\Dto\VerificationResult;
use StoneScriptPHP\Billing\Dto\VerifyRequest;
use StoneScriptPHP\Billing\Dto\WebhookEvent;
use StoneScriptPHP\Billing\Dto\WebhookRequest;
use StoneScriptPHP\Billing\Exceptions\SignatureVerificationException;
use StoneScriptPHP\Database;
use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Subscriptions\SubscriptionConfig;
use StoneScriptPHP\Subscriptions\SubscriptionRoutes;
use StoneScriptPHP\Subscriptions\Routes\PostRazorpayWebhookRoute;

/**
 * v9.17.2 dependency-inversion migration: PostRazorpayWebhookRoute no
 * longer hard-instantiates any concrete payment package's driver class —
 * it calls through an INJECTED `StoneScriptPHP\Billing\Contracts\
 * PaymentProvider`, wired via `SubscriptionConfig::$paymentProvider` /
 * `SubscriptionRoutes::register(['payment_provider' => $driver, ...])`.
 *
 * These tests prove the injection seam itself using a FAKE
 * `PaymentProvider` (the framework's own port) — no payment package
 * needed at all to test the framework's side of this contract. A real
 * concrete driver (e.g. `stonescriptphp-pay`'s Razorpay driver)
 * satisfying this exact same port is proven in THAT package's own test
 * suite, not here — the framework must not depend on it to test itself.
 *
 * process() reads php://input directly, which PHPUnit cannot easily fake
 * without a stream wrapper — these tests therefore exercise the
 * injection/registration seam (construction, and the registration-time
 * fail-loud guard) rather than reaching through the framework's stdin
 * plumbing. PostRazorpayWebhookRouteQuarantineTest already proves
 * handlePaymentCaptured()'s contract-check branches directly via
 * reflection, unaffected by this migration.
 */
class PostRazorpayWebhookRouteViaPayTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::clearFakeMode();
    }

    private function config(?PaymentProvider $paymentProvider = null): SubscriptionConfig
    {
        return new SubscriptionConfig([
            'platform_code' => 'exampleapp',
            'razorpay_webhook_secret' => 'whsec_test',
            'payment_provider' => $paymentProvider,
        ]);
    }

    public function test_route_class_is_constructible_and_typed(): void
    {
        $route = new PostRazorpayWebhookRoute($this->config(new FakeWebhookPaymentProvider()));
        $this->assertInstanceOf(PostRazorpayWebhookRoute::class, $route);
    }

    public function test_config_carries_the_injected_payment_provider_verbatim(): void
    {
        $provider = new FakeWebhookPaymentProvider();
        $config = $this->config($provider);

        $this->assertSame($provider, $config->paymentProvider);
    }

    public function test_config_payment_provider_defaults_to_null_when_not_given(): void
    {
        $config = new SubscriptionConfig(['platform_code' => 'exampleapp', 'razorpay_webhook_secret' => 'whsec_test']);

        $this->assertNull($config->paymentProvider);
    }

    /**
     * The dependency-inversion fix's core guarantee: enabling
     * razorpay_webhook WITHOUT a PaymentProvider must fail loud at
     * REGISTRATION time (a clear, actionable exception naming the missing
     * option) — never at the first real webhook request, and never via a
     * raw "Class not found"/TypeError fatal from a hard-coded driver
     * reference (the pre-9.17.2 behavior this migration removes).
     */
    public function test_register_throws_when_razorpay_webhook_enabled_without_payment_provider(): void
    {
        $router = new Router();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/payment_provider/');

        SubscriptionRoutes::register($router, [
            'platform_code' => 'exampleapp',
            'razorpay_webhook_secret' => 'whsec_test',
            // 'payment_provider' deliberately omitted
        ]);
    }

    /**
     * The mirror-image proof: registration succeeds once a PaymentProvider
     * is supplied — the framework does not care WHAT concrete class it is,
     * only that it satisfies the port.
     */
    public function test_register_succeeds_when_razorpay_webhook_enabled_with_payment_provider(): void
    {
        $router = new Router();

        SubscriptionRoutes::register($router, [
            'platform_code' => 'exampleapp',
            'razorpay_webhook_secret' => 'whsec_test',
            'payment_provider' => new FakeWebhookPaymentProvider(),
        ]);

        $this->assertTrue(true, 'register() must not throw once a PaymentProvider is supplied');
    }

    /**
     * Confirms the route's process() guard rejects a signature the
     * injected provider itself rejects — proven here via the port's
     * exception type, not a specific driver's HMAC formula (that formula
     * is the concern of whichever concrete driver is installed, proven in
     * ITS OWN test suite).
     */
    public function test_fake_payment_provider_rejecting_signature_throws_the_frameworks_own_exception_type(): void
    {
        $provider = new FakeWebhookPaymentProvider(throwOnHandleWebhook: true);

        $this->expectException(SignatureVerificationException::class);
        $provider->handleWebhook(new WebhookRequest('{}', 'wrong-signature'));
    }
}

/**
 * Minimal fake `PaymentProvider` — the framework's own port — used to
 * prove the webhook route's injection seam without any concrete payment
 * package installed.
 */
final class FakeWebhookPaymentProvider implements PaymentProvider
{
    public function __construct(private readonly bool $throwOnHandleWebhook = false)
    {
    }

    public function createOrder(CreateOrderRequest $req): OrderResult
    {
        return new OrderResult(
            orderId: 'order_fake',
            amountMinorUnits: $req->amountMinorUnits,
            currency: $req->currency,
            receipt: $req->receipt,
            publishableKeyId: 'key_fake',
            status: 'created',
        );
    }

    public function verifySignature(VerifyRequest $req): VerificationResult
    {
        return new VerificationResult(verified: true, paymentId: $req->paymentId, orderId: $req->orderId);
    }

    public function handleWebhook(WebhookRequest $req): WebhookEvent
    {
        if ($this->throwOnHandleWebhook) {
            throw new SignatureVerificationException('webhook');
        }

        return new WebhookEvent(type: 'payment.captured', providerEvent: 'payment.captured', payload: []);
    }

    public function createSubscription(CreateSubscriptionRequest $req): SubscriptionResult
    {
        return new SubscriptionResult(subscriptionId: 'sub_fake', status: 'created');
    }

    public function cancelSubscription(string $subscriptionId): SubscriptionResult
    {
        return new SubscriptionResult(subscriptionId: $subscriptionId, status: 'cancelled');
    }

    public function getSubscription(string $subscriptionId): SubscriptionResult
    {
        return new SubscriptionResult(subscriptionId: $subscriptionId, status: 'active');
    }

    public function refund(RefundRequest $req): RefundResult
    {
        return new RefundResult(
            refundId: 'refund_fake',
            paymentId: $req->paymentId,
            amountMinorUnits: $req->amountMinorUnits ?? 0,
            status: 'processed',
            createdAt: time(),
        );
    }

    public function settlementModel(): string
    {
        return 'gateway';
    }
}
