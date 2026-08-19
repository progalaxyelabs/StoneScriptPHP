<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Database;
use StoneScriptPHP\Subscriptions\SubscriptionConfig;
use StoneScriptPHP\Webhooks\WebhookQuarantine;

/**
 * POST /subscription/webhook/razorpay
 *
 * Receives Razorpay payment webhooks. Verifies HMAC-SHA256 signature, matches
 * payment to a tenant subscription by owner_email, and auto-activates.
 *
 * Public endpoint (no JWT) — verified by Razorpay HMAC-SHA256 signature.
 * Processes locally — no curl forwarding.
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

        if (empty($signature)) {
            error_log('[Razorpay Webhook] Missing X-Razorpay-Signature header');
            return res_error('Missing signature', 400);
        }

        $expectedSignature = hash_hmac('sha256', $rawBody, $webhookSecret);
        if (!hash_equals($expectedSignature, $signature)) {
            error_log('[Razorpay Webhook] Signature verification FAILED');
            return res_error('Invalid signature', 400);
        }

        $payload = json_decode($rawBody, true);
        if (!$payload || !is_array($payload)) {
            // SIGNATURE ALREADY VERIFIED above — this is a genuinely malformed
            // envelope from an otherwise-authentic sender, not a spoofed request.
            // Quarantine rather than drop: never lose a signed payment event.
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

        $event = $payload['event'] ?? '';
        error_log("[Razorpay Webhook] Event: {$event}");

        if ($event === 'payment.captured') {
            $this->handlePaymentCaptured($payload);
        } else {
            error_log("[Razorpay Webhook] Ignoring event: {$event}");
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
