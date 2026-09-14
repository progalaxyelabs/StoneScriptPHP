<?php

declare(strict_types=1);

namespace StoneScriptPHP\Billing\Contracts;

/**
 * The invoicing-side contract — the minimal operations the framework needs
 * from ANY invoicing system to drive an automated collection. Implement
 * this against `stonescriptphp-invoice` (see
 * `progalaxyelabs/stonescriptphp-invoice`'s `InvoiceSourceAdapter`), a
 * third-party invoicing system (Zoho, QuickBooks, Stripe Invoicing), or a
 * hand-rolled ledger.
 *
 * Design invariants (see the framework's Billing/README.md for the full
 * integration procedure):
 *
 *   - The invoicing system DECIDES amount, currency, gateway routing, and
 *     payability. The framework only ASKS via resolvePayableIntent() and
 *     relays the answer — it never computes or overrides any of it.
 *   - recordVerifiedPayment() folds "record the payment idempotently" and
 *     "transition to paid/settled" into ONE atomic call, by design — never
 *     split into two contract calls. Splitting them would open a
 *     non-atomic window and would push the "should this settle the
 *     invoice?" decision into PHP, violating the ecosystem law that
 *     business logic lives in the data layer, not in PHP glue.
 *   - recordVerifiedPayment() is called ONLY after the caller (`pay`) has
 *     already verified the provider signature/webhook. The invoicing
 *     implementation trusts the amount/txn-ref handed to it came from a
 *     verified event; it does not re-verify signatures.
 *   - $invoiceRef is an OPAQUE string end to end — the framework never
 *     assumes its type or internal structure.
 */
interface InvoiceSource
{
    /**
     * Resolve the payable intent for an invoice/payable.
     *
     * Returns amount, currency, the chosen gateway code, an
     * idempotency/reference key, the hosted checkout target, and whether
     * it is payable at all (with a reason). Called by
     * {@see \StoneScriptPHP\Billing\CollectionOrchestrator::initiateCollection()}
     * before creating a payment order — never after.
     *
     * @param string $invoiceRef Opaque key identifying the invoice/payable.
     * @return PayableIntent
     */
    public function resolvePayableIntent(string $invoiceRef): PayableIntent;

    /**
     * Record a verified payment against an invoice/payable IDEMPOTENTLY
     * and, on success, transition it to a settled/paid state.
     *
     * A replay of the same gateway transaction reference MUST be a no-op
     * that returns the same result (`RecordPaymentResult::$alreadyRecorded
     * === true`) rather than raising an error or double-applying the
     * payment. The implementation owns the amount/currency-match check and
     * the single-success guard — the framework performs neither.
     *
     * @param RecordPaymentRequest $req
     * @return RecordPaymentResult
     */
    public function recordVerifiedPayment(RecordPaymentRequest $req): RecordPaymentResult;
}
