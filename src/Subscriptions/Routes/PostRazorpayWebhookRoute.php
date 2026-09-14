<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Billing\Dto\WebhookRequest;
use StoneScriptPHP\Billing\Exceptions\SignatureVerificationException;
use StoneScriptPHP\Billing\Exceptions\WebhookException;
use StoneScriptPHP\Database;
use StoneScriptPHP\Subscriptions\SubscriptionConfig;
use StoneScriptPHP\Webhooks\WebhookQuarantine;

/**
 * POST /subscription/webhook/razorpay
 *
 * Receives Razorpay payment webhooks. Verifies the signature via an
 * INJECTED `StoneScriptPHP\Billing\Contracts\PaymentProvider` (v9.17.2+ —
 * the framework no longer hard-references any concrete payment package's
 * driver class directly; the consuming app builds a `PaymentProvider`
 * implementation — e.g. a small adapter wrapping the
 * `progalaxyelabs/stonescriptphp-pay` package's Razorpay driver (`pay` is
 * a standalone library with its own, differently-namespaced contract —
 * see `Billing/README.md`'s bridging section for the adapter shape), or a
 * hand-rolled driver implementing this interface directly — and passes it
 * via `SubscriptionRoutes::register($router, ['payment_provider' =>
 * $driver, ...])`, which flows through `SubscriptionConfig::$paymentProvider`
 * to this route. This is the ports-and-adapters direction: the framework
 * defines the port (`Billing\Contracts\PaymentProvider`), an
 * app-owned driver/adapter satisfies it, the CONSUMING APP wires the two
 * together — the framework itself depends on no concrete payment package,
 * and no payment package depends on the framework. Before v9.17.2 this
 * route directly instantiated a payment package's driver class, which was
 * the same backwards-dependency defect `Billing\Contracts\PaymentContract`
 * had.
 *
 * matches payment to a tenant subscription by owner_email, and
 * auto-activates.
 *
 * NOT routed through `StoneScriptPHP\Billing\CollectionOrchestrator`,
 * even though that seam exists elsewhere in this framework — a deliberate
 * call, not an oversight. This module's `sub_*` domain identifies a
 * payment's owner by e-mail (`sub_find_by_email`), not by an
 * `invoiceRef` an order was tagged with at creation
 * time — this route never creates the order in the first place (order
 * creation happens outside this framework module, in the consuming app),
 * so no `notes.invoice_ref` is ever set for `CollectionOrchestrator` to
 * extract. Forcing this through `InvoiceSource`/the orchestrator would
 * either (a) silently no-op on every real webhook (invoiceRef always
 * null → the orchestrator throws), or (b) require inventing an
 * email-as-invoiceRef business mapping inside PHP glue, which is worse
 * than the status quo, not better. An injected `PaymentProvider` is used
 * for what it actually offers here — shared, tested signature
 * verification + canonical event dispatch — and nothing more is forced.
 *
 * Public endpoint (no JWT) — verified by Razorpay's signature via the
 * injected provider. Processes locally — no curl forwarding.
 *
 * @package StoneScriptPHP\Subscriptions\Routes
 */
class PostRazorpayWebhookRoute implements IRouteHandler
{
    public function __construct(private readonly SubscriptionConfig $config)
    {
    }

    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        $rawBody = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

        $webhookSecret = $this->config->razorpayWebhookSecret ?? '';

        if (empty($webhookSecret)) {
            error_log('[Razorpay Webhook] razorpay_webhook_secret not configured');
            return res_error('Webhook not configured', 503);
        }

        // v9.17.2+: the framework no longer instantiates any concrete
        // driver itself — it only ever calls through the injected
        // `Billing\Contracts\PaymentProvider` port. `SubscriptionRoutes::
        // register()` already refuses to register this route without one
        // (fails loud at registration time), so reaching process() with a
        // null provider means the route was constructed directly,
        // bypassing register() — guard defensively rather than trust that.
        $driver = $this->config->paymentProvider;
        if ($driver === null) {
            error_log('[Razorpay Webhook] No PaymentProvider injected via SubscriptionConfig::$paymentProvider');
            return res_error(
                'Server misconfiguration: razorpay_webhook requires a PaymentProvider — build one '
                . '(e.g. an adapter wrapping composer require progalaxyelabs/stonescriptphp-pay\'s '
                . "Razorpay driver — see Billing/README.md) and pass it as 'payment_provider' => "
                . '$driver to SubscriptionRoutes::register()',
                503
            );
        }

        try {
            $event = $driver->handleWebhook(new WebhookRequest($rawBody, $signature));
        } catch (SignatureVerificationException $e) {
            error_log('[Razorpay Webhook] Signature verification FAILED: ' . $e->getMessage());
            return res_error(empty($signature) ? 'Missing signature' : 'Invalid signature', 400);
        } catch (WebhookException $e) {
            // The driver verifies the signature BEFORE parsing JSON (same
            // ordering the old inline code used), so reaching here means the
            // signature was genuinely verified and the body is what's
            // malformed — a genuinely malformed envelope from an otherwise-
            // authentic sender, not a spoofed request. Quarantine rather
            // than drop: never lose a signed payment event.
            WebhookQuarantine::quarantine(
                $this->config->platformCode ?? '',
                'razorpay',
                null,
                'Signature verified but body is not valid JSON',
                $this->requestHeaders(),
                ['raw_body_excerpt' => substr($rawBody, 0, 2000)]
            );
            error_log('[Razorpay Webhook] Invalid JSON payload — quarantined');
            return res_error('Invalid payload', 400);
        }

        error_log("[Razorpay Webhook] Event: {$event->providerEvent}");

        if ($event->isPaymentCaptured()) {
            // handlePaymentCaptured() expects the FULL decoded webhook
            // envelope (unchanged signature/shape) — $event->raw is exactly
            // that (WebhookEvent::$payload is the narrower payment.entity
            // projection; $raw is the whole body, same as the old $payload
            // local variable this call site used to pass).
            $this->handlePaymentCaptured($event->raw);
        } else {
            error_log("[Razorpay Webhook] Ignoring event: {$event->providerEvent}");
        }

        return res_ok(['status' => 'received']);
    }

    /**
     * Current request headers, lowercased-key associative array — used only
     * to persist alongside a quarantined envelope (see WebhookQuarantine).
     */
    private function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            return is_array($headers) ? $headers : [];
        }
        return [];
    }

    private function handlePaymentCaptured(array $payload): void
    {
        $payment = $payload['payload']['payment']['entity'] ?? [];

        // CONTRACT CHECK — a payment.captured event whose `entity` is missing
        // the fields this handler NEEDS to act correctly is exactly the case
        // WebhookQuarantine exists for: this is a payment path, so silently
        // proceeding with defaulted/guessed values (the old `?? 'unknown'` /
        // `?? 0` behavior) risked activating the wrong subscription or losing
        // the ability to ever reconcile the payment. Quarantine instead of
        // silently-absorbing.
        $paymentId = $payment['id'] ?? null;
        $amountPaise = $payment['amount'] ?? null;
        $email = isset($payment['email']) ? strtolower(trim((string) $payment['email'])) : '';

        if (!is_string($paymentId) || $paymentId === '' || !is_int($amountPaise) || $email === '') {
            WebhookQuarantine::quarantine(
                $this->config->platformCode ?? '',
                'razorpay',
                'payment.captured',
                'payment.captured entity missing required id/amount/email field(s) — refusing to guess',
                $this->requestHeaders(),
                $payload
            );
            return;
        }

        $amountCents = $amountPaise; // Razorpay sends in paise = cents for INR
        $phone = $this->normalizePhone((string) ($payment['contact'] ?? ''));
        $method = (string) ($payment['method'] ?? '');

        error_log("[Razorpay Webhook] Payment captured: id={$paymentId}, amount_paise={$amountPaise}, email={$email}, phone={$phone}");

        try {
            $gw = Database::getGatewayClient();
            $prev = $gw->getTenantId();
            $gw->setTenantId(null);

            try {
                // Find subscription by email
                $result = Database::fn('sub_find_by_email', [$email]);
                $row = $result[0] ?? null;
                if (is_object($row)) {
                    $row = (array) $row;
                }
                if (isset($row['sub_find_by_email'])) {
                    $sub = is_string($row['sub_find_by_email'])
                        ? json_decode($row['sub_find_by_email'], true)
                        : $row['sub_find_by_email'];
                } else {
                    $sub = $row;
                }

                if (!$sub || empty($sub['tenant_id'])) {
                    // A real, signature-verified payment we cannot attribute to any
                    // tenant is a billing-critical event, not a routine log line —
                    // quarantine it so it surfaces for manual reconciliation instead
                    // of being buried in application logs (this is the exact
                    // "silently-absorbs" anti-pattern WebhookQuarantine exists to end).
                    WebhookQuarantine::quarantine(
                        $this->config->platformCode ?? '',
                        'razorpay',
                        'payment.captured',
                        "Verified payment {$paymentId} has no matching subscription for email={$email} — MANUAL ACTIVATION NEEDED",
                        $this->requestHeaders(),
                        $payload
                    );
                    return;
                }

                // Get the annual plan for this platform
                $planResult = Database::fn('sub_get_plan', [$sub['platform_code'], 'annual']);
                $planRow = $planResult[0] ?? null;
                if (is_object($planRow)) {
                    $planRow = (array) $planRow;
                }
                if (isset($planRow['sub_get_plan'])) {
                    $plan = is_string($planRow['sub_get_plan'])
                        ? json_decode($planRow['sub_get_plan'], true)
                        : $planRow['sub_get_plan'];
                } else {
                    $plan = $planRow;
                }

                $durationDays = $plan['duration_days'] ?? 365;

                Database::fn('sub_activate', [
                    $sub['platform_code'],
                    $sub['tenant_id'],
                    'annual',
                    $durationDays,
                    $paymentId,
                    $email,
                    $phone,
                    $amountCents,
                    $method,
                    json_encode($payload),
                ]);

                error_log("[Razorpay Webhook] Subscription ACTIVATED: platform={$sub['platform_code']}, tenant={$sub['tenant_id']}, payment={$paymentId}");
            } finally {
                $gw->setTenantId($prev);
            }
        } catch (\Exception $e) {
            error_log("[Razorpay Webhook] Error processing payment {$paymentId}: " . $e->getMessage());
        }
    }

    /**
     * Normalize Indian phone numbers to E.164 format (+91XXXXXXXXXX).
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 10) {
            return '+91' . $digits;
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return '+' . $digits;
        }

        return str_starts_with($phone, '+') ? $phone : '+' . $digits;
    }
}
