<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Database;
use StoneScriptPHP\Subscriptions\SubscriptionConfig;

/**
 * GET /subscription/plans
 *
 * Returns available subscription plans for the platform.
 * Platform code comes from config (set at registration time).
 *
 * @package StoneScriptPHP\Subscriptions\Routes
 */
class GetSubscriptionPlansRoute implements IRouteHandler
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
        $user = auth();
        if (!$user) {
            return res_error('Authentication required', 401);
        }

        $platformCode = $this->config->platformCode;

        if (empty($platformCode)) {
            return res_error('Platform code not configured', 500);
        }

        try {
            $gw = Database::getGatewayClient();
            $prev = $gw->getTenantId();
            $gw->setTenantId(null);

            try {
                $result = Database::fn('sub_list_plans', [$platformCode]);
            } finally {
                $gw->setTenantId($prev);
            }

            $data = $result[0] ?? null;
            if (is_object($data)) {
                $data = (array) $data;
            }
            // Gateway pre-decodes JSON — handle both string and array forms
            if (isset($data['sub_list_plans'])) {
                $plans = is_string($data['sub_list_plans'])
                    ? json_decode($data['sub_list_plans'], true)
                    : $data['sub_list_plans'];
            } else {
                $plans = $data;
            }

            return res_ok($plans ?? []);
        } catch (\Exception $e) {
            error_log('[Subscription Plans] Error: ' . $e->getMessage());
            return res_error('Failed to retrieve subscription plans');
        }
    }
}
