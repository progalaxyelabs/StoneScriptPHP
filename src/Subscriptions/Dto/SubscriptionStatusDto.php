<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions\Dto;

/**
 * Response contract for GET {prefix}/status. Mirrors the `sub_get_status`
 * SQL function's `json_build_object(...)` (src/Subscriptions/Schema/functions/sub_get_status.pgsql).
 * Timestamps are ISO-8601 strings on the wire (Postgres `json_build_object`
 * serializes `timestamptz` columns as strings; the gateway pre-decodes JSON
 * but does not further parse them into a richer type).
 *
 * NOTE: when no subscription exists for the tenant, this route returns 404
 * (`res_not_ok`), never a 200 with this shape empty — the client must check
 * the HTTP status / `ApiResponse.status`, not treat missing fields as "no
 * subscription".
 *
 * @package StoneScriptPHP\Subscriptions\Dto
 */
class SubscriptionStatusDto
{
    public string $id = '';
    public string $platform_code = '';
    public string $tenant_id = '';
    public string $owner_email = '';
    public string $plan_code = '';
    public string $status = '';
    public bool $is_trial = false;
    public bool $is_active = false;
    public int $days_remaining = 0;
    public string $started_at = '';
    public string $expires_at = '';
    public string $created_at = '';
}
