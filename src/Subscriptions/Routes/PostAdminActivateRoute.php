<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Database;
use StoneScriptPHP\Subscriptions\SubscriptionConfig;

/**
 * POST /subscription/admin/activate
 *
 * Admin-only endpoint to manually activate a subscription.
 * Authenticated via X-Admin-Key header (not JWT).
 *
 * Body:
 * {
 *   "platform_code": "medstoreapp",
 *   "tenant_id": "uuid",
 *   "plan_code": "annual",
 *   "payment_id": "pay_xxx",      (optional)
 *   "payer_email": "owner@example.com",  (optional)
 *   "payer_phone": "+91XXXXXXXXXX",      (optional)
 *   "amount_cents": 418800              (optional)
 * }
 *
 * @package StoneScriptPHP\Subscriptions\Routes
 */
class PostAdminActivateRoute implements IRouteHandler
{
    public ?string $platform_code = null;
    public ?string $tenant_id = null;
    public ?string $plan_code = null;
    public ?string $payment_id = null;
    public ?string $payer_email = null;
    public ?string $payer_phone = null;
    public ?int $amount_cents = null;

    public function __construct(private readonly SubscriptionConfig $config)
    {
    }

    public function validation_rules(): array
    {
        return [
            'platform_code' => 'required|string',
            'tenant_id' => 'required|string',
            'plan_code' => 'required|string',
        ];
    }

    public function process(): ApiResponse
    {
        // Verify admin API key
        $adminKey = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
        $expectedKey = $this->config->adminApiKey ?? '';

        if (empty($expectedKey) || !hash_equals($expectedKey, $adminKey)) {
            return res_error('Unauthorized', 401);
        }

        try {
            $gw = Database::getGatewayClient();
            $prev = $gw->getTenantId();
            $gw->setTenantId(null);

            try {
                // Look up plan duration
                $planResult = Database::fn('sub_get_plan', [$this->platform_code, $this->plan_code]);
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

                if (!$plan) {
                    return res_error("Plan '{$this->plan_code}' not found for platform '{$this->platform_code}'", 404);
                }

                $durationDays = $plan['duration_days'] ?? 365;

                $result = Database::fn('sub_activate', [
                    $this->platform_code,
                    $this->tenant_id,
                    $this->plan_code,
                    $durationDays,
                    $this->payment_id ?? 'manual',
                    $this->payer_email ?? '',
                    $this->payer_phone ?? '',
                    $this->amount_cents ?? 0,
                    '',   // payment_method
                    '{}', // raw_payload
                ]);

                $row = $result[0] ?? null;
                if (is_object($row)) {
                    $row = (array) $row;
                }
                if (isset($row['sub_activate'])) {
                    $data = is_string($row['sub_activate'])
                        ? json_decode($row['sub_activate'], true)
                        : $row['sub_activate'];
                } else {
                    $data = $row;
                }

                if (!$data) {
                    return res_error('Activation failed', 500);
                }
            } finally {
                $gw->setTenantId($prev);
            }

            error_log("[Admin Activate] Subscription activated: platform={$this->platform_code}, tenant={$this->tenant_id}, plan={$this->plan_code}");

            return res_ok($data, 'Subscription activated');
        } catch (\Exception $e) {
            error_log('[Admin Activate] Error: ' . $e->getMessage());
            return res_error('Activation failed: ' . $e->getMessage());
        }
    }
}
