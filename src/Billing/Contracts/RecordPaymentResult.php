<?php

declare(strict_types=1);

namespace StoneScriptPHP\Billing\Contracts;

/**
 * The result of {@see InvoiceSource::recordVerifiedPayment()}.
 *
 * Immutable, no logic — the invoicing implementation already made every
 * decision (idempotent-replay detection, the due->paid transition); this
 * DTO only reports what happened.
 */
final class RecordPaymentResult
{
    /**
     * @param string $paymentRef Opaque reference to the recorded payment
     *   row in the invoicing system (e.g. our `inv_payments.id`).
     * @param bool $alreadyRecorded True when this call was a replay of an
     *   already-recorded gateway transaction (idempotent no-op) — the
     *   webhook route should still ack 2xx in this case, never treat it as
     *   an error.
     * @param bool $invoiceSettled True when the invoice/payable reached a
     *   terminal paid/settled state as a result of this call (or already
     *   had, on replay).
     */
    public function __construct(
        public readonly string $paymentRef,
        public readonly bool $alreadyRecorded,
        public readonly bool $invoiceSettled,
    ) {
    }
}
