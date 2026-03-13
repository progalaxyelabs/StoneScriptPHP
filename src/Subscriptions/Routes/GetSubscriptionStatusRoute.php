<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Database;
use StoneScriptPHP\Subscriptions\SubscriptionConfig;

/**
 * GET /subscription/status
 *
 * Returns subscription status for the authenticated tenant.
 * Pure read — returns 404 if no subscription exists.
 * Trial creation is the provisioning flow's responsibility.
 *
 * @package StoneScriptPHP\Subscriptions\Routes
 */
class GetSubscriptionStatusRoute implements IRouteHandler
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

        $tenantId = $user->tenant_id ?? null;

        if (empty($tenantId)) {
            return res_error('Missing tenant_id in JWT claims', 400);
        }

        try {
            $gw = Database::getGatewayClient();
            $prev = $gw->getTenantId();
            $gw->setTenantId(null);

            try {
                $result = Database::fn('sub_get_status', [$tenantId]);
            } finally {
                $gw->setTenantId($prev);
            }

            $data = $result[0] ?? null;
            if (is_object($data)) {
                $data = (array) $data;
            }
            // Gateway pre-decodes JSON — handle both string and array forms
            if (isset($data['sub_get_status'])) {
                $data = is_string($data['sub_get_status'])
                    ? json_decode($data['sub_get_status'], true)
                    : $data['sub_get_status'];
            }

            if (!$data) {
                return res_not_ok('No subscription found for this tenant', 404);
            }

            return res_ok($data);
        } catch (\Exception $e) {
            error_log('[Subscription Status] Error: ' . $e->getMessage());
            return res_error('Failed to retrieve subscription status');
        }
    }
}
