<?php

declare(strict_types=1);

namespace StoneScriptPHP\Billing\Contracts;

/**
 * The two settlement models a `PaymentProvider` driver can report via
 * `settlementModel()`.
 *
 * GATEWAY — the consuming application is the merchant of record and is
 *   responsible for GST/tax invoicing (e.g. Razorpay, PayPal-as-gateway).
 *   `CollectionOrchestrator` expects an `InvoiceSource` to be wired for
 *   these drivers.
 *
 * MOR — the provider is the seller of record and handles tax/invoicing
 *   itself (e.g. Paddle, LemonSqueezy). `CollectionOrchestrator` is
 *   constructed with a `null` `InvoiceSource` for these drivers; there is
 *   nothing to invoice locally.
 */
final class SettlementModel
{
    public const GATEWAY = 'gateway';
    public const MOR = 'mor';

    private function __construct()
    {
        // static-only
    }
}
