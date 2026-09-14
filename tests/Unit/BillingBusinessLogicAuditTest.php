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
 * THE business-logic audit — executable assertions, not just prose —
 * proving the framework's Billing glue smuggles NO amounts/tax/routing
 * DECISIONS into PHP — every
 * number/route the orchestrator ever emits is one it was HANDED by the
 * bound InvoiceSource/PaymentProvider, never one it computed.
 *
 * Method: feed the orchestrator adversarial-looking (but internally
 * consistent) fake InvoiceSource/PaymentProvider responses whose numbers
 * would be OBVIOUSLY WRONG if the orchestrator recomputed or altered them
 * (e.g. an odd, arbitrary amount; a currency the orchestrator has never
 * seen; a gateway code outside GatewayCode's published set) — and assert
 * they pass through byte-for-byte. If the orchestrator ever "helpfully"
 * rounded, reformatted, or defaulted a value, one of these assertions
 * breaks.
 */
class BillingBusinessLogicAuditTest extends TestCase
{
    /**
     * AUDIT 1 — amount/currency/receipt are RELAYED verbatim into
     * createOrder(), never recomputed. An arbitrary, non-round amount
     * (12345 minor units — not a "clean" number a bug might coincidentally
     * reproduce) and an unusual currency prove there is no hidden rounding,
     * currency-mapping table, or unit conversion inside the orchestrator.
     */
    public function test_initiate_collection_never_recomputes_amount_currency_or_receipt(): void
    {
        $oddAmount = 12345; // deliberately not round/typical
        $oddCurrency = 'AED'; // deliberately not INR/USD, the two currencies any hardcoded path would assume
        $oddReceipt = 'ZZZ-audit-receipt-937';

        $invoices = new AuditFakeInvoiceSource(new PayableIntent(
            isPayable: true,
            reason: 'payable',
            amountMinorUnits: $oddAmount,
            currency: $oddCurrency,
            gatewayCode: GatewayCode::RAZORPAY,
            reference: $oddReceipt,
            checkoutEndpoint: 'https://pay.example.com/audit',
        ));
        $payment = new AuditFakePaymentProvider();
        $orchestrator = new CollectionOrchestrator($payment, $invoices, GatewayCode::RAZORPAY);

        $checkout = $orchestrator->initiateCollection('audit_invoice_ref');

        $this->assertSame($oddAmount, $payment->lastCreateOrderRequest?->amountMinorUnits);
        $this->assertSame($oddCurrency, $payment->lastCreateOrderRequest?->currency);
        $this->assertSame($oddReceipt, $payment->lastCreateOrderRequest?->receipt);
        $this->assertSame($oddAmount, $checkout->amountMinorUnits);
        $this->assertSame($oddCurrency, $checkout->currency);
    }

    /**
     * AUDIT 2 — the orchestrator does not gate/validate the intent's
     * gatewayCode against GatewayCode::isKnown() itself (that would be a
     * routing DECISION — "is this a valid rail?" belongs to whoever chose
     * it). It passes whatever the invoicing system decided straight
     * through to checkout info, unexamined.
     */
    public function test_initiate_collection_does_not_validate_or_alter_gateway_code(): void
    {
        $invoices = new AuditFakeInvoiceSource(new PayableIntent(
            isPayable: true,
            reason: 'payable',
            amountMinorUnits: 100,
            currency: 'INR',
            gatewayCode: 'a_gateway_code_not_in_GatewayCode_all', // intentionally unpublished
            reference: 'ref',
            checkoutEndpoint: 'https://pay.example.com/audit2',
        ));
        $payment = new AuditFakePaymentProvider();
        $orchestrator = new CollectionOrchestrator($payment, $invoices, GatewayCode::RAZORPAY);

        // Must NOT throw — the orchestrator does not police the invoicing
        // system's routing decision; it only relays it.
        $checkout = $orchestrator->initiateCollection('audit_invoice_ref_2');
        $this->assertTrue($checkout->isPayable);
    }

    /**
     * AUDIT 3 — settleFromWebhook() relays the driver's normalised
     * amount/currency/txn-ref/timestamp verbatim into RecordPaymentRequest
     * — no recomputation, no "cents to rupees"-style conversion, no
     * defaulting of a missing value to a guessed one (a missing required
     * field FAILS LOUD instead — see the throws test in
     * CollectionOrchestratorTest).
     */
    public function test_settle_from_webhook_never_recomputes_captured_amount_or_currency(): void
    {
        $oddAmount = 987654;
        $oddCurrency = 'JPY'; // 0-decimal currency — proves no decimal-assumption creeps in here
        $capturedAt = new \DateTimeImmutable('2026-01-02T03:04:05+00:00');

        $invoices = new AuditFakeInvoiceSource(recordResult: new RecordPaymentResult(
            paymentRef: 'pay_row_audit',
            alreadyRecorded: false,
            invoiceSettled: true,
        ));
        $payment = new AuditFakePaymentProvider(webhookEvent: new WebhookEvent(
            type: 'payment.captured',
            providerEvent: 'payment.captured',
            payload: [],
            invoiceRef: 'audit_ref',
            gatewayTxnRef: 'txn_audit_1',
            amountMinorUnits: $oddAmount,
            currency: $oddCurrency,
            capturedAt: $capturedAt,
        ));
        $orchestrator = new CollectionOrchestrator($payment, $invoices, GatewayCode::PAYPAL);

        $orchestrator->settleFromWebhook(new WebhookRequest('{}', 'sig'));

        $req = $invoices->lastRecordPaymentRequest;
        $this->assertNotNull($req);
        $this->assertSame($oddAmount, $req->amountMinorUnits);
        $this->assertSame($oddCurrency, $req->currency);
        $this->assertSame('txn_audit_1', $req->gatewayTxnRef);
        $this->assertSame($capturedAt, $req->capturedAt);
        // gatewayCode tagged onto the payment record is the orchestrator's
        // OWN constructor binding (one orchestrator == one driver == one
        // gateway) — a lookup of which instance is running, not a routing
        // decision (the routing decision already happened inside whatever
        // resolved this event to THIS orchestrator instance).
        $this->assertSame(GatewayCode::PAYPAL, $req->gatewayCode);
    }

    /**
     * AUDIT 4 — the orchestrator assigns no invoice number, computes no
     * tax, and never calls anything resembling a numbering/tax function.
     * Static proof: grep the orchestrator's source for tax/numbering/
     * currency-conversion vocabulary. Business logic in this ecosystem is
     * expressed in SQL function names like inv_issue_invoice / GST rate
     * math — none of that vocabulary belongs in PHP glue.
     */
    public function test_orchestrator_source_contains_no_tax_or_numbering_vocabulary(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Billing/CollectionOrchestrator.php');
        $this->assertIsString($source);

        $forbidden = [
            'gst', 'cgst', 'sgst', 'igst', 'tax_rate', 'invoice_number',
            'round_off', 'hsn', 'place_of_supply', 'fx_rate',
        ];
        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsStringIgnoringCase(
                $needle,
                $source,
                "CollectionOrchestrator source unexpectedly references '$needle' — "
                . 'business-logic vocabulary has no place in the orchestrator.'
            );
        }
    }
}

final class AuditFakePaymentProvider implements PaymentProvider
{
    public ?CreateOrderRequest $lastCreateOrderRequest = null;

    public function __construct(private readonly ?WebhookEvent $webhookEvent = null)
    {
    }

    public function createOrder(CreateOrderRequest $req): OrderResult
    {
        $this->lastCreateOrderRequest = $req;

        return new OrderResult(
            orderId: 'order_audit',
            amountMinorUnits: $req->amountMinorUnits,
            currency: $req->currency,
            receipt: $req->receipt,
            publishableKeyId: 'key_audit',
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
        return new SubscriptionResult(subscriptionId: 'sub_audit', status: 'created');
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
            refundId: 'refund_audit',
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

final class AuditFakeInvoiceSource implements InvoiceSource
{
    public ?RecordPaymentRequest $lastRecordPaymentRequest = null;

    public function __construct(
        private readonly ?PayableIntent $intent = null,
        private readonly ?RecordPaymentResult $recordResult = null,
    ) {
    }

    public function resolvePayableIntent(string $invoiceRef): PayableIntent
    {
        return $this->intent ?? throw new \LogicException('no fake intent configured');
    }

    public function recordVerifiedPayment(RecordPaymentRequest $req): RecordPaymentResult
    {
        $this->lastRecordPaymentRequest = $req;

        return $this->recordResult ?? new RecordPaymentResult('pay_row_audit', false, true);
    }
}
