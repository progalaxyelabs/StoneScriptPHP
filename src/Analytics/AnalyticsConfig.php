<?php

declare(strict_types=1);

namespace StoneScriptPHP\Analytics;

/**
 * Analytics Module Configuration
 *
 * Value object that validates and normalises options passed to
 * AnalyticsRoutes::register().
 *
 * Usage:
 *
 *   AnalyticsRoutes::register($router, [
 *       'enabled'      => true,
 *       'prefix'       => '/portal/analytics',  // optional, default shown
 *       'table_name'   => 'analytics_events',   // optional, default shown
 *       'rate_limit'   => 60,                   // events per minute per IP (soft limit)
 *   ]);
 *
 * @package StoneScriptPHP\Analytics
 */
class AnalyticsConfig
{
    /** URL prefix for analytics routes */
    public readonly string $prefix;

    /** Database table name for stored events */
    public readonly string $tableName;

    /**
     * Maximum events per IP address per minute.
     *
     * Enforced per PHP-FPM worker process (in-memory). With multiple workers
     * the effective limit is rateLimit × workerCount. Sufficient for protecting
     * against burst abuse; use Redis-backed limiting for strict global enforcement.
     */
    public readonly int $rateLimit;

    /** Whether the analytics module is enabled */
    public readonly bool $enabled;

    /**
     * @param array $options Raw options from AnalyticsRoutes::register()
     */
    public function __construct(array $options = [])
    {
        $this->enabled   = $options['enabled']    ?? true;
        $this->prefix    = rtrim($options['prefix'] ?? '/portal/analytics', '/');
        $this->tableName = $options['table_name'] ?? 'analytics_events';
        $this->rateLimit = max(1, (int) ($options['rate_limit'] ?? 60));
    }
}
