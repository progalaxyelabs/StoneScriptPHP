<?php

declare(strict_types=1);

namespace StoneScriptPHP\Billing;

use StoneScriptPay\Contracts\PaymentProvider;
use StoneScriptPay\DTO\CreateOrderRequest;
use StoneScriptPay\DTO\WebhookRequest;
use StoneScriptPHP\Billing\Contracts\GatewayCode;
use StoneScriptPHP\Billing\Contracts\InvoiceSource;
use StoneScriptPHP\Billing\Contracts\RecordPaymentRequest;

/**
 * Reference glue binding a `PaymentProvider` (from `stonescriptphp-pay`) and
 * an OPTIONAL `InvoiceSource` for the two collection flows. Ships in the
 * framework — not in `pay` or `invoice` — because it is the only place
 * that can type-hint both contracts while keeping each package standalone.
 *
 * Contains ONLY sequencing + marshalling — zero business decisions. It
 * computes no amount, decides no tax, assigns no invoice number, and
 * chooses no gateway ROUTE. It picks a driver/gateway-code by the
 * already-decided value the invoicing system (or the constructor caller)
 * supplied — a lookup, never a decision. See
 * `Tests\Unit\BillingBusinessLogicAuditTest` for the line-by-line proof.
 *
 * Third-party integration paths (A/B/C/D — reference vs alternate
 * invoicing vs MoR-no-invoicing vs alternate payment module) are
 * documented in `Billing/README.md`.
 */
final class CollectionOrchestrator
{
    /**
     * @param PaymentProvider $payment The bound payment driver (e.g.
     *   `StoneScriptPay\Drivers\RazorpayDriver`).
     * @param ?InvoiceSource $invoices NULL under MoR / no-local-invoicing
     *   (see {@see \StoneScriptPHP\Billing\Contracts\SettlementModel}).
     * @param string $gatewayCode This orchestrator instance's gateway code
     *   (see {@see GatewayCode}) — one orchestrator is bound to one
     *   payment driver, hence one gateway code. Used to tag recorded
     *   payments; NOT used to route/choose a gateway (that decision, when
     *   an invoicing system supports more than one rail, already happened
     *   inside `$invoices->resolvePayableIntent()`).
     */
    public function __construct(
        private readonly PaymentProvider $payment,
        private readonly ?InvoiceSource $invoices = null,
        private readonly string $gatewayCode = GatewayCode::RAZORPAY,
    ) {
    }

    /**
     * FLOW 1 — initiate collection. Returns checkout info for the CALLER
     * to redirect the payer to (a hosted checkout / central pay page) —
     * never embeds/iframes a checkout itself.
     *
     * Requires an `InvoiceSource` — under MoR (no local invoicing) a
     * project initiates checkout directly against its `PaymentProvider`
     * driver instead of through this flow; there is no invoice to resolve
     * a payable intent from.
     *
     * @throws \LogicException if constructed without an InvoiceSource.
     */
    public function initiateCollection(string $invoiceRef): CheckoutInfo
    {
        if ($this->invoices === null) {
            throw new \LogicException(
                'CollectionOrchestrator::initiateCollection() requires an InvoiceSource. '
                . 'This instance was constructed with none (MoR / no-local-invoicing mode) — '
                . 'under MoR the provider owns checkout initiation directly; see Billing/README.md path C.'
            );
        }

        // The invoicing system DECIDES amount/currency/gateway/payability —
        // the orchestrator only asks and relays.
        $intent = $this->invoices->resolvePayableIntent($invoiceRef);

        if (!$intent->isPayable) {
            // No order created — e.g. already_paid.
            return new CheckoutInfo(isPayable: false, reason: $intent->reason);
        }

        // Amount/currency/receipt are the server-set values the invoicing
        // system returned — NEVER client input.
        $order = $this->payment->createOrder(new CreateOrderRequest(
            amountMinorUnits: $intent->amountMinorUnits,
            currency: $intent->currency,
            receipt: $intent->reference ?? $invoiceRef,
            notes: ['invoice_ref' => $invoiceRef],
        ));

        return new CheckoutInfo(
            isPayable: true,
            reason: $intent->reason,
            orderId: $order->orderId,
            publishableKeyId: $order->publishableKeyId,
            checkoutEndpoint: $intent->checkoutEndpoint,
            amountMinorUnits: $intent->amountMinorUnits,
            currency: $intent->currency,
        );
    }

    /**
     * FLOW 2 — settle on webhook. Verifies + parses via `pay`
     * (`PaymentProvider::handleWebhook()` — verification lives entirely in
     * `pay`, never re-implemented here), then — on a capture/charge event —
     * records the verified payment via the bound `InvoiceSource` (or, under
     * MoR, simply acknowledges — there is nothing to record locally).
     *
     * Always safe to call repeatedly for the same event (the invoicing
     * side's idempotent-replay guarantee flows straight through to
     * `$outcome->alreadyRecorded`) — the caller should ack 2xx whenever
     * `$outcome->wasHandled` is true, including a replay, mirroring
     * `WebhookQuarantine`'s "never lose or duplicate a signed event" rule.
     */
    public function settleFromWebhook(WebhookRequest $req): SettlementOutcome
    {
        // Verification + parsing lives ENTIRELY in `pay` — throws on a bad
        // signature, which the caller lets propagate (401/400).
        $event = $this->payment->handleWebhook($req);

        $isSettlementEvent = $event->isPaymentCaptured() || $event->type === 'subscription.charged';
        if (!$isSettlementEvent) {
            // Not a capture/charge event — nothing to settle. Not an error.
            return SettlementOutcome::unhandled();
        }

        if ($this->invoices === null) {
            // MoR — the provider is seller of record; nothing to record locally.
            return SettlementOutcome::acknowledgedMor();
        }

        // Marshalling only — these fields are populated by the DRIVER from
        // its own provider-native payload shape (see WebhookEvent's
        // docblock); the orchestrator never parses provider-specific JSON
        // itself, which is what keeps it usable across drivers.
        if (
            $event->invoiceRef === null
            || $event->gatewayTxnRef === null
            || $event->amountMinorUnits === null
            || $event->currency === null
        ) {
            throw new \RuntimeException(
                "CollectionOrchestrator: driver returned a '{$event->type}' event without the "
                . 'normalised invoiceRef/gatewayTxnRef/amountMinorUnits/currency fields needed to '
                . 'record a payment. The driver must populate WebhookEvent\'s normalised fields for '
                . 'settlement event types.'
            );
        }

        $result = $this->invoices->recordVerifiedPayment(new RecordPaymentRequest(
            invoiceRef: $event->invoiceRef,
            gatewayCode: $this->gatewayCode,
            gatewayTxnRef: $event->gatewayTxnRef,
            amountMinorUnits: $event->amountMinorUnits,
            currency: $event->currency,
            status: 'success',
            capturedAt: $event->capturedAt ?? new \DateTimeImmutable(),
        ));

        return new SettlementOutcome(
            wasHandled: true,
            invoiceSettled: $result->invoiceSettled,
            alreadyRecorded: $result->alreadyRecorded,
        );
    }
}
