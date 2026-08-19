<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions\Dto;

/**
 * One element of the GET {prefix}/plans response (collection: true). Mirrors
 * the columns selected by the `sub_list_plans` SQL function
 * (src/Subscriptions/Schema/functions/sub_list_plans.pgsql).
 *
 * @package StoneScriptPHP\Subscriptions\Dto
 */
class SubscriptionPlanDto
{
    public string $id = '';
    public string $platform_code = '';
    public string $plan_code = '';
    public string $display_name = '';
    public int $amount_cents = 0;
    public string $currency = '';
    public int $duration_days = 0;
}
