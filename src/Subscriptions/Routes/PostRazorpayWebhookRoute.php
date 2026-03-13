<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Database;
use StoneScriptPHP\Subscriptions\SubscriptionConfig;

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
            error_log('[Razorpay Webhook] Invalid JSON payload');
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

    private function handlePaymentCaptured(array $payload): void
    {
        $payment = $payload['payload']['payment']['entity'] ?? [];

        $paymentId = $payment['id'] ?? 'unknown';
        $amountPaise = $payment['amount'] ?? 0;
        $amountCents = (int) $amountPaise; // Razorpay sends in paise = cents for INR
        $email = strtolower(trim($payment['email'] ?? ''));
        $phone = $this->normalizePhone($payment['contact'] ?? '');
        $method = $payment['method'] ?? '';

        error_log("[Razorpay Webhook] Payment captured: id={$paymentId}, amount_paise={$amountPaise}, email={$email}, phone={$phone}");

        if (empty($email)) {
            error_log("[Razorpay Webhook] No email in payment {$paymentId} — cannot match tenant");
            return;
        }

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
                    error_log("[Razorpay Webhook] No subscription found for email={$email}, payment_id={$paymentId}");
                    error_log("[Razorpay Webhook] MANUAL ACTIVATION NEEDED: email={$email}, phone={$phone}, payment_id={$paymentId}, amount_paise={$amountPaise}");
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
