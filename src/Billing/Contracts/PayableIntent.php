<?php

declare(strict_types=1);

namespace StoneScriptPHP\Billing\Contracts;

/**
 * The result of {@see InvoiceSource::resolvePayableIntent()}.
 *
 * Carries the amount/currency/gateway/checkout-target the INVOICING SYSTEM
 * decided — this DTO never computes anything, it only transports the
 * decision already made in SQL (or whatever the invoicing implementation's
 * source of truth is). `CollectionOrchestrator` relays these values
 * verbatim into `CreateOrderRequest` — it makes no amount/currency/gateway
 * choice of its own.
 *
 * Immutable, minor-unit ints only, no logic.
 */
final class PayableIntent
{
    /**
     * @param bool $isPayable Whether a collection should be started at all.
     * @param string $reason Machine-readable reason, e.g. 'payable' |
     *   'already_paid' | 'not_payable_status'. Implementation-defined
     *   beyond those three baseline values — callers should not assume an
     *   exhaustive enum.
     * @param int $amountMinorUnits Amount in minor units (paise/cents).
     *   Meaningless when $isPayable is false but still populated with the
     *   invoice's total for display purposes.
     * @param string $currency ISO 4217 currency code.
     * @param ?string $gatewayCode The routed gateway code (see
     *   {@see GatewayCode}), NULL unless $isPayable is true.
     * @param ?string $reference Idempotency/receipt key to tag the payment
     *   order with (e.g. derived from an invoice number), NULL unless
     *   $isPayable is true.
     * @param ?string $checkoutEndpoint Hosted checkout / central pay page
     *   URL to redirect the payer to, NULL unless $isPayable is true.
     */
    public function __construct(
        public readonly bool $isPayable,
        public readonly string $reason,
        public readonly int $amountMinorUnits,
        public readonly string $currency,
        public readonly ?string $gatewayCode = null,
        public readonly ?string $reference = null,
        public readonly ?string $checkoutEndpoint = null,
    ) {
    }
}
