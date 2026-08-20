<?php

declare(strict_types=1);

namespace StoneScriptPHP\Webhooks;

use StoneScriptPHP\Database;
use StoneScriptPHP\Binding\TypedArray;

/**
 * Ingestion-seam safety net for inbound third-party webhooks (payments, job
 * callbacks, etc.).
 *
 * A webhook route stays fully typed everywhere EXCEPT the ingestion seam: the
 * inbound envelope is untyped by definition (it comes from a third party we
 * don't control). The rule this module enforces:
 *
 *   Once a webhook's SIGNATURE has been verified (so we know it's really
 *   from the provider), if the payload then fails to match the typed
 *   contract this route expects (missing/malformed required fields, an
 *   unrecognized shape, etc.), the route MUST call
 *   {@see WebhookQuarantine::quarantine()} — not silently proceed with
 *   defaulted/guessed field values, and not just error_log() and return.
 *   This is a payment/business-critical path: losing an event because it
 *   didn't fit the shape we expected is unacceptable. Quarantining:
 *     1. Persists the RAW envelope (headers + payload, secrets redacted)
 *        untouched, so it can be replayed/inspected/manually resolved later.
 *     2. Raises a log_alert() so the failure surfaces immediately instead of
 *        being buried in routine logs.
 *     3. Still lets the ROUTE decide its own HTTP response to the provider
 *        (usually 200 — once signature-verified, most providers retry
 *        indefinitely on non-2xx, and a retry storm doesn't help once we've
 *        already captured + alerted; but that decision belongs to the route,
 *        not this module).
 *
 * This is NOT a general "log stuff" helper and NOT an `@open` escape hatch —
 * it exists exclusively at the untyped ingestion boundary. Everything past
 * that boundary (once the payload is confirmed to match its contract) stays
 * fully typed, same as every other route in the framework.
 *
 * Schema: see {@see WebhookQuarantine::getSchemaFiles()} — platforms opt in
 * by copying/symlinking the returned files, same convention as
 * SubscriptionRoutes::getSchemaFiles() / AnalyticsRoutes::getSchemaFiles().
 *
 * @package StoneScriptPHP\Webhooks
 */
class WebhookQuarantine
{
    /**
     * Header name fragments (case-insensitive) whose values are redacted
     * before persisting — the raw envelope must never leak a secret the
     * provider sent (signature headers, auth headers) into a DB row that
     * may be read by a wider audience than "who can read prod secrets".
     */
    private const REDACT_HEADER_PATTERNS = ['signature', 'authorization', 'secret', 'token', 'api-key', 'apikey'];

    /**
     * Persist a raw, contract-failing webhook envelope; alert; quarantine.
     * Never throws — a quarantine-path failure must not become a second,
     * worse failure in the webhook route's own error handling. If the
     * persist itself fails, that is escalated to log_emergency() (this is
     * the last line of defense for a payment-path event — losing it here
     * with no trace at all is the one truly unacceptable outcome).
     *
     * @param string      $platformCode This platform's code (for multi-tenant/
     *                                   multi-brand webhook endpoints).
     * @param string      $source       Provider identifier, e.g. 'razorpay'.
     * @param string|null $eventType    The provider's event type, if it could
     *                                   be determined at all (e.g. 'payment.captured').
     *                                   Null when even that couldn't be parsed.
     * @param string      $reason       Human-readable description of exactly
     *                                   which contract expectation failed —
     *                                   this is what an operator reads to
     *                                   decide how to resolve the quarantine
     *                                   entry, so be specific (e.g. "payment
     *                                   entity missing 'id' field", not "bad
     *                                   payload").
     * @param array       $rawHeaders   Inbound request headers (associative).
     * @param array       $rawPayload   The verified-signature payload, decoded
     *                                   but otherwise UNTOUCHED — this is
     *                                   what gets persisted for replay.
     */
    public static function quarantine(
        string $platformCode,
        string $source,
        ?string $eventType,
        string $reason,
        array $rawHeaders,
        array $rawPayload
    ): void {
        log_alert("WebhookQuarantine: $source webhook failed its typed contract — quarantined, not applied", [
            'platform_code' => $platformCode,
            'source'        => $source,
            'event_type'    => $eventType,
            'reason'        => $reason,
        ]);

        try {
            $gw   = Database::getGatewayClient();
            $prev = $gw->getTenantId();
            $gw->setTenantId(null);

            try {
                Database::fn('webhook_quarantine_insert', [
                    $platformCode,
                    $source,
                    $eventType,
                    $reason,
                    json_encode(self::redactHeaders($rawHeaders)),
                    json_encode($rawPayload),
                ]);
            } finally {
                $gw->setTenantId($prev);
            }
        } catch (\Throwable $e) {
            // The quarantine INSERT itself failing must not be silent —
            // this is the last line of defense for a payment-path event.
            log_emergency(
                "WebhookQuarantine: FAILED TO PERSIST quarantined $source webhook — event may be UNRECOVERABLE",
                [
                    'platform_code' => $platformCode,
                    'source'        => $source,
                    'event_type'    => $eventType,
                    'reason'        => $reason,
                    'persist_error' => $e->getMessage(),
                ]
            );
        }
    }

    /**
     * Redact header values whose NAME matches a known-sensitive fragment
     * (case-insensitive substring match against {@see REDACT_HEADER_PATTERNS}).
     *
     * @param array<string,mixed> $headers
     * @return array<string,mixed>
     */
    private static function redactHeaders(array $headers): array
    {
        $redacted = [];
        foreach ($headers as $name => $value) {
            $isSensitive = false;
            foreach (self::REDACT_HEADER_PATTERNS as $pattern) {
                if (stripos((string) $name, $pattern) !== false) {
                    $isSensitive = true;
                    break;
                }
            }
            $redacted[$name] = $isSensitive ? '[redacted]' : $value;
        }
        return $redacted;
    }

    /**
     * Get absolute paths to the SQL schema files bundled with this module.
     *
     * Platforms with a webhook route should copy (or symlink) these files
     * into their own postgresql/main/postgresql/tables/ and functions/
     * directories, then run `php stone migrate up` — same convention as
     * SubscriptionRoutes::getSchemaFiles() / AnalyticsRoutes::getSchemaFiles().
     *
     * @return array{tables: TypedArray<string>, functions: TypedArray<string>}
     */
    public static function getSchemaFiles(): array
    {
        $schemaDir = __DIR__ . '/Schema';

        return [
            'tables' => new TypedArray('string', [
                $schemaDir . '/tables/webhook_001_quarantine.pgsql',
            ]),
            'functions' => new TypedArray('string', [
                $schemaDir . '/functions/webhook_quarantine_insert.pgsql',
            ]),
        ];
    }
}
