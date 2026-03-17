<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions;

/**
 * Subscription Module Configuration
 *
 * Value object that validates and normalizes options passed to
 * SubscriptionRoutes::register() and SubscriptionMiddleware.
 *
 * Usage:
 *
 *   SubscriptionRoutes::register($router, [
 *       'platform_code' => 'my_trading_platform',
 *       'razorpay_webhook_secret' => 'whsec_xxx',   // enables webhook
 *       'admin_api_key' => 'secret-key',             // enables admin activate
 *       'prefix' => '/subscription',                 // optional, default: /subscription
 *   ]);
 *
 * @package StoneScriptPHP\Subscriptions
 */
class SubscriptionConfig
{
    /** URL prefix for all subscription routes (default: /subscription) */
    public readonly string $prefix;

    /** Platform code for plan lookups (required) */
    public readonly string $platformCode;

    /** Razorpay webhook HMAC secret — required to enable razorpay_webhook */
    public readonly ?string $razorpayWebhookSecret;

    /** Admin API key for X-Admin-Key header authentication */
    public readonly ?string $adminApiKey;

    /** Path prefixes / exact paths that bypass SubscriptionMiddleware enforcement */
    public readonly array $exemptPaths;

    /** @var array<string, bool> Feature toggles */
    private array $features;

    /**
     * @param array $options Raw options from SubscriptionRoutes::register()
     */
    public function __construct(array $options = [])
    {
        $env = \StoneScriptPHP\Env::get_instance();

        $this->prefix = rtrim($options['prefix'] ?? '/subscription', '/');
        $this->platformCode = $options['platform_code'] ?? ($env->PLATFORM_CODE ?? '');

        $this->razorpayWebhookSecret = $options['razorpay_webhook_secret']
            ?? ($env->RAZORPAY_WEBHOOK_SECRET ?? null);

        $this->adminApiKey = $options['admin_api_key']
            ?? ($env->ADMIN_API_KEY ?? null);

        $this->exemptPaths = $options['exempt_paths'] ?? [
            '/health',
            '/auth/',
            '/subscription/status',
            '/account/',
            '/export',
        ];

        // Feature toggles — razorpay_webhook and admin_activate are opt-in
        $this->features = [
            'status'            => $options['status'] ?? true,
            'plans'             => $options['plans'] ?? true,
            'razorpay_webhook'  => $options['razorpay_webhook'] ?? !empty($this->razorpayWebhookSecret),
            'admin_activate'    => $options['admin_activate'] ?? !empty($this->adminApiKey),
        ];
    }

    /**
     * Check if a feature is enabled.
     *
     * @param string $feature Feature key
     * @return bool
     */
    public function isEnabled(string $feature): bool
    {
        return $this->features[$feature] ?? false;
    }

    /**
     * Get all feature toggle states.
     *
     * @return array<string, bool>
     */
    public function getFeatures(): array
    {
        return $this->features;
    }
}
