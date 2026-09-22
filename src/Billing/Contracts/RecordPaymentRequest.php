<?php

declare(strict_types=1);

namespace StoneScriptPHP\Billing\Contracts;

/**
 * Input to {@see InvoiceSource::recordVerifiedPayment()}.
 *
 * By construction time, `pay` has ALREADY verified the signature/webhook —
 * `recordVerifiedPayment` is called only with values that came from a
 * verified provider event. The invoicing implementation owns the
 * amount/currency-match check and the single-success guard; this DTO
 * never performs either.
 *
 * Immutable, minor-unit ints only, no logic.
 */
final class RecordPaymentRequest
{
    /**
     * @param string $invoiceRef Opaque key identifying the invoice/payable
     *   in the invoicing system's own vocabulary — the framework never
     *   assumes a type (BIGINT id, UUID, external invoice number, ...).
     * @param string $tenantId The tenant that owns this invoice (added
     *   2026-09-22, TENANT ISOLATION — see {@see InvoiceSource}'s class
     *   docblock). For the webhook-settlement path
     *   ({@see \StoneScriptPHP\Billing\CollectionOrchestrator::settleFromWebhook()})
     *   there is no authenticated caller to derive this from directly — it
     *   MUST instead be round-tripped through the gateway's own
     *   notes/metadata (set at order-creation time from the tenant that
     *   initiated checkout, echoed back verbatim in the verified webhook
     *   payload) so the SAME tenant-ownership check applies to the reverse
     *   leg as to a direct call. The implementation still enforces the
     *   match against the invoice it actually owns; this DTO only carries
     *   the value through.
     * @param string $platformCode Paired with $tenantId.
     * @param string $gatewayCode See {@see GatewayCode}.
     * @param string $gatewayTxnRef The gateway's own payment/capture/txn id
     *   — the idempotency key a replay is deduplicated on.
     * @param int $amountMinorUnits Amount the gateway captured, in minor
     *   units (paise/cents).
     * @param string $currency ISO 4217 currency code.
     * @param string $status 'success' | 'failed'.
     * @param \DateTimeImmutable $capturedAt The GATEWAY's own capture time
     *   (a business input from the provider, never the framework's now()).
     */
    public function __construct(
        public readonly string $invoiceRef,
        public readonly string $tenantId,
        public readonly string $platformCode,
        public readonly string $gatewayCode,
        public readonly string $gatewayTxnRef,
        public readonly int $amountMinorUnits,
        public readonly string $currency,
        public readonly string $status,
        public readonly \DateTimeImmutable $capturedAt,
    ) {
    }
}
