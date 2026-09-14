<?php

declare(strict_types=1);

namespace StoneScriptPHP\Billing;

/**
 * Result of {@see CollectionOrchestrator::initiateCollection()}.
 *
 * Carries what the caller needs to REDIRECT the payer to a hosted
 * checkout / central pay page — never to render an embedded/iframed
 * checkout (see the anti-iframe policy). When $isPayable is false, no
 * order was created; $reason explains why (e.g. 'already_paid').
 */
final class CheckoutInfo
{
    public function __construct(
        public readonly bool $isPayable,
        public readonly string $reason,
        public readonly ?string $orderId = null,
        public readonly ?string $publishableKeyId = null,
        public readonly ?string $checkoutEndpoint = null,
        public readonly ?int $amountMinorUnits = null,
        public readonly ?string $currency = null,
    ) {
    }
}
