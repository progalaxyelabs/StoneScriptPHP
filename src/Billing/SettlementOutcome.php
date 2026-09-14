<?php

declare(strict_types=1);

namespace StoneScriptPHP\Billing;

/**
 * Result of {@see CollectionOrchestrator::settleFromWebhook()}.
 *
 * The webhook route should ack 2xx whenever $wasHandled is true — including
 * an idempotent replay ($alreadyRecorded === true) — never lose or
 * duplicate a signed provider event. A genuinely malformed-but-signed
 * envelope is the caller's job to quarantine (mirroring
 * `StoneScriptPHP\Webhooks\WebhookQuarantine`), not this DTO's.
 */
final class SettlementOutcome
{
    /**
     * @param bool $wasHandled True once the event type was recognised and
     *   processed (even as a MoR-acknowledged or idempotent-replay no-op).
     *   False for an event type the orchestrator does not act on (the
     *   route should still ack 2xx — an unrecognised/irrelevant event is
     *   not an error).
     * @param bool $invoiceSettled True when the underlying invoice/payable
     *   reached (or already was at) a terminal paid/settled state.
     * @param bool $alreadyRecorded True when this was an idempotent replay
     *   of an already-recorded gateway transaction.
     * @param bool $isMor True when the bound `PaymentProvider` is a
     *   merchant-of-record driver — there is no local invoicing to record
     *   against ($invoices was null), so nothing was recorded locally.
     */
    public function __construct(
        public readonly bool $wasHandled,
        public readonly bool $invoiceSettled = false,
        public readonly bool $alreadyRecorded = false,
        public readonly bool $isMor = false,
    ) {
    }

    public static function unhandled(): self
    {
        return new self(wasHandled: false);
    }

    public static function acknowledgedMor(): self
    {
        return new self(wasHandled: true, isMor: true);
    }
}
