<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPay\Contracts\PaymentProvider;
use StoneScriptPay\DTO\CreateOrderRequest;
use StoneScriptPay\DTO\CreateSubscriptionRequest;
use StoneScriptPay\DTO\OrderResult;
use StoneScriptPay\DTO\RefundRequest;
use StoneScriptPay\DTO\RefundResult;
use StoneScriptPay\DTO\SubscriptionResult;
use StoneScriptPay\DTO\VerificationResult;
use StoneScriptPay\DTO\VerifyRequest;
use StoneScriptPay\DTO\WebhookEvent;
use StoneScriptPay\DTO\WebhookRequest;
use StoneScriptPHP\Billing\CollectionOrchestrator;
use StoneScriptPHP\Billing\Contracts\GatewayCode;
use StoneScriptPHP\Billing\Contracts\InvoiceSource;
use StoneScriptPHP\Billing\Contracts\PayableIntent;
use StoneScriptPHP\Billing\Contracts\RecordPaymentRequest;
use StoneScriptPHP\Billing\Contracts\RecordPaymentResult;

/**
 * Proves CollectionOrchestrator's two flows, the MoR-null path, and the
 * already-paid short-circuit, against FAKE PaymentProvider/InvoiceSource
 * implementations — no real gateway/DB needed (the orchestrator is pure
 * sequencing/marshalling; Tier-3 SQL correctness is verified separately,
 * per the framework's testing conventions).
 */
class CollectionOrchestratorTest extends TestCase
{
    public function test_initiate_collection_creates_order_from_invoicing_decided_intent(): void
    {
        $invoices = new FakeInvoiceSourceForOrchestrator([
            'inv_1' => new PayableIntent(
                isPayable: true,
                reason: 'payable',
                amountMinorUnits: 49900,
                currency: 'INR',
                gatewayCode: GatewayCode::RAZORPAY,
                reference: 'INV-1',
                checkoutEndpoint: 'https://pay.example.com/inv_1',
            ),
        ]);
        $payment = new FakePaymentProviderForOrchestrator();
        $orchestrator = new CollectionOrchestrator($payment, $invoices, GatewayCode::RAZORPAY);

        $checkout = $orchestrator->initiateCollection('inv_1');

        $this->assertTrue($checkout->isPayable);
        $this->assertSame('order_fake_1', $checkout->orderId);
        $this->assertSame('https://pay.example.com/inv_1', $checkout->checkoutEndpoint);
        $this->assertSame(49900, $checkout->amountMinorUnits);
        $this->assertSame('INR', $checkout->currency);

        // The amount/currency/receipt passed to createOrder() came VERBATIM
        // from the intent — the orchestrator computed nothing.
        $this->assertNotNull($payment->lastCreateOrderRequest);
        $this->assertSame(49900, $payment->lastCreateOrderRequest->amountMinorUnits);
        $this->assertSame('INR', $payment->lastCreateOrderRequest->currency);
        $this->assertSame('INV-1', $payment->lastCreateOrderRequest->receipt);
        $this->assertSame('inv_1', $payment->lastCreateOrderRequest->notes['invoice_ref']);
    }

    public function test_initiate_collection_short_circuits_when_already_paid_no_order_created(): void
    {
        $invoices = new FakeInvoiceSourceForOrchestrator([
            'inv_paid' => new PayableIntent(
                isPayable: false,
                reason: 'already_paid',
                amountMinorUnits: 49900,
                currency: 'INR',
            ),
        ]);
        $payment = new FakePaymentProviderForOrchestrator();
        $orchestrator = new CollectionOrchestrator($payment, $invoices, GatewayCode::RAZORPAY);

        $checkout = $orchestrator->initiateCollection('inv_paid');

        $this->assertFalse($checkout->isPayable);
        $this->assertSame('already_paid', $checkout->reason);
        $this->assertNull($checkout->orderId);
        $this->assertNull($payment->lastCreateOrderRequest, 'no order should be created for a non-payable intent');
    }

    public function test_initiate_collection_throws_without_invoice_source(): void
    {
        $payment = new FakePaymentProviderForOrchestrator();
        $orchestrator = new CollectionOrchestrator($payment, null, GatewayCode::RAZORPAY);

        $this->expectException(\LogicException::class);
        $orchestrator->initiateCollection('inv_1');
    }

    public function test_settle_from_webhook_records_verified_payment_and_reports_settlement(): void
    {
        $invoices = new FakeInvoiceSourceForOrchestrator([], recordResult: new RecordPaymentResult(
            paymentRef: 'pay_row_1',
            alreadyRecorded: false,
            invoiceSettled: true,
        ));
        $payment = new FakePaymentProviderForOrchestrator(webhookEvent: new WebhookEvent(
            type: 'payment.captured',
            providerEvent: 'payment.captured',
            payload: [],
            invoiceRef: 'inv_1',
            gatewayTxnRef: 'pay_ABC',
            amountMinorUnits: 49900,
            currency: 'INR',
            capturedAt: new \DateTimeImmutable('2026-09-14T00:00:00+00:00'),
        ));
        $orchestrator = new CollectionOrchestrator($payment, $invoices, GatewayCode::RAZORPAY);

        $outcome = $orchestrator->settleFromWebhook(new WebhookRequest('{}', 'sig'));

        $this->assertTrue($outcome->wasHandled);
        $this->assertTrue($outcome->invoiceSettled);
        $this->assertFalse($outcome->alreadyRecorded);
        $this->assertFalse($outcome->isMor);

        $this->assertNotNull($invoices->lastRecordPaymentRequest);
        $this->assertSame('inv_1', $invoices->lastRecordPaymentRequest->invoiceRef);
        $this->assertSame(GatewayCode::RAZORPAY, $invoices->lastRecordPaymentRequest->gatewayCode);
        $this->assertSame('pay_ABC', $invoices->lastRecordPaymentRequest->gatewayTxnRef);
        $this->assertSame(49900, $invoices->lastRecordPaymentRequest->amountMinorUnits);
        $this->assertSame('INR', $invoices->lastRecordPaymentRequest->currency);
        $this->assertSame('success', $invoices->lastRecordPaymentRequest->status);
    }

    public function test_settle_from_webhook_replay_reports_already_recorded(): void
    {
        $invoices = new FakeInvoiceSourceForOrchestrator([], recordResult: new RecordPaymentResult(
            paymentRef: 'pay_row_1',
            alreadyRecorded: true,
            invoiceSettled: true,
        ));
        $payment = new FakePaymentProviderForOrchestrator(webhookEvent: new WebhookEvent(
            type: 'payment.captured',
            providerEvent: 'payment.captured',
            payload: [],
            invoiceRef: 'inv_1',
            gatewayTxnRef: 'pay_ABC',
            amountMinorUnits: 49900,
            currency: 'INR',
            capturedAt: new \DateTimeImmutable(),
        ));
        $orchestrator = new CollectionOrchestrator($payment, $invoices, GatewayCode::RAZORPAY);

        $outcome = $orchestrator->settleFromWebhook(new WebhookRequest('{}', 'sig'));

        $this->assertTrue($outcome->wasHandled);
        $this->assertTrue($outcome->alreadyRecorded, 'a replay must be reported as already-recorded, never re-applied');
    }

    public function test_settle_from_webhook_ignores_non_settlement_event(): void
    {
        $invoices = new FakeInvoiceSourceForOrchestrator([]);
        $payment = new FakePaymentProviderForOrchestrator(webhookEvent: new WebhookEvent(
            type: 'payment.failed',
            providerEvent: 'payment.failed',
            payload: [],
        ));
        $orchestrator = new CollectionOrchestrator($payment, $invoices, GatewayCode::RAZORPAY);

        $outcome = $orchestrator->settleFromWebhook(new WebhookRequest('{}', 'sig'));

        $this->assertFalse($outcome->wasHandled);
        $this->assertNull($invoices->lastRecordPaymentRequest, 'a non-settlement event must never reach recordVerifiedPayment');
    }

    /**
     * The MoR-null path (§7/§8 path C): constructed with NO InvoiceSource,
     * a payment.captured event is acknowledged WITHOUT ever calling any
     * invoicing method (there is none to call) — nothing recorded locally.
     */
    public function test_settle_from_webhook_under_mor_acknowledges_without_invoice_source(): void
    {
        $payment = new FakePaymentProviderForOrchestrator(webhookEvent: new WebhookEvent(
            type: 'payment.captured',
            providerEvent: 'payment.captured',
            payload: [],
            invoiceRef: 'inv_1',
            gatewayTxnRef: 'pay_ABC',
            amountMinorUnits: 49900,
            currency: 'USD',
            capturedAt: new \DateTimeImmutable(),
        ));
        $orchestrator = new CollectionOrchestrator($payment, null, GatewayCode::PAYPAL);

        $outcome = $orchestrator->settleFromWebhook(new WebhookRequest('{}', 'sig'));

        $this->assertTrue($outcome->wasHandled);
        $this->assertTrue($outcome->isMor);
        $this->assertFalse($outcome->invoiceSettled);
        $this->assertFalse($outcome->alreadyRecorded);
    }

    public function test_settle_from_webhook_throws_when_driver_omits_normalised_fields(): void
    {
        $invoices = new FakeInvoiceSourceForOrchestrator([]);
        // A settlement event whose driver did NOT populate the normalised
        // fields — the orchestrator must fail loud, never guess/parse the
        // provider-native payload itself (that would smuggle provider-shape
        // knowledge, i.e. logic, into the orchestrator).
        $payment = new FakePaymentProviderForOrchestrator(webhookEvent: new WebhookEvent(
            type: 'payment.captured',
            providerEvent: 'payment.captured',
            payload: ['some' => 'provider-native-shape'],
        ));
        $orchestrator = new CollectionOrchestrator($payment, $invoices, GatewayCode::RAZORPAY);

        $this->expectException(\RuntimeException::class);
        $orchestrator->settleFromWebhook(new WebhookRequest('{}', 'sig'));
    }
}

/**
 * Fake PaymentProvider — records the last createOrder() call for assertion
 * and returns a pre-programmed webhook event. No network, no real SDK.
 */
final class FakePaymentProviderForOrchestrator implements PaymentProvider
{
    public ?CreateOrderRequest $lastCreateOrderRequest = null;

    public function __construct(private readonly ?WebhookEvent $webhookEvent = null)
    {
    }

    public function createOrder(CreateOrderRequest $req): OrderResult
    {
        $this->lastCreateOrderRequest = $req;

        return new OrderResult(
            orderId: 'order_fake_1',
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
        return $this->webhookEvent ?? new WebhookEvent('unknown', '', []);
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

/**
 * Fake InvoiceSource — resolves a pre-programmed intent by invoiceRef and
 * records the last recordVerifiedPayment() call for assertion.
 */
final class FakeInvoiceSourceForOrchestrator implements InvoiceSource
{
    public ?RecordPaymentRequest $lastRecordPaymentRequest = null;

    /**
     * @param array<string, PayableIntent> $intentsByRef
     */
    public function __construct(
        private readonly array $intentsByRef = [],
        private readonly ?RecordPaymentResult $recordResult = null,
    ) {
    }

    public function resolvePayableIntent(string $invoiceRef): PayableIntent
    {
        return $this->intentsByRef[$invoiceRef]
            ?? throw new \OutOfBoundsException("no fake intent registered for invoiceRef '$invoiceRef'");
    }

    public function recordVerifiedPayment(RecordPaymentRequest $req): RecordPaymentResult
    {
        $this->lastRecordPaymentRequest = $req;

        return $this->recordResult ?? new RecordPaymentResult(
            paymentRef: 'pay_row_fake',
            alreadyRecorded: false,
            invoiceSettled: true,
        );
    }
}
