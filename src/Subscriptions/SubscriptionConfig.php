<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions;

use StoneScriptPHP\Billing\Contracts\PaymentProvider;

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
 *       'payment_provider' => $driver,               // required with the webhook above —
 *                                                     // a StoneScriptPHP\Billing\Contracts\PaymentProvider
 *                                                     // (e.g. an adapter wrapping stonescriptphp-payments's
 *                                                     // Razorpay driver — see Billing/README.md)
 *       'admin_api_key' => 'secret-key',             // enables admin activate
 *       'prefix' => '/subscription',                 // optional, default: /subscription
 *       'expired_mode' => 'read_only',               // default; 'block' = legacy 402 lockout
 *       'write_allow_list' => ['/devices/pair'],     // extra writes allowed while read-only
 *       'warning_days' => 7,                         // X-Subscription-State warning window
 *       'missing_subscription' => 'read_only',       // or 'allow'
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

    /**
     * The `PaymentProvider` used to verify + parse the razorpay_webhook
     * route's inbound webhooks (v9.17.2+ — the framework no longer
     * constructs a concrete driver itself; the consuming app builds one
     * and passes it in via `$options['payment_provider']`). Required by
     * `SubscriptionRoutes::register()` whenever razorpay_webhook is
     * enabled — see that method for the fail-loud check.
     */
    public readonly ?PaymentProvider $paymentProvider;

    /** Admin API key for X-Admin-Key header authentication */
    public readonly ?string $adminApiKey;

    /** Path prefixes / exact paths that bypass SubscriptionMiddleware enforcement */
    public readonly array $exemptPaths;

    /** 'read_only' (default, HTTP 423 on writes) or 'block' (legacy HTTP 402 lockout) */
    public readonly string $expiredMode;

    /** EXTRA paths whose writes stay allowed in read_only mode (merged with the safe defaults) */
    public readonly array $writeAllowList;

    /** Days before expiry at which the X-Subscription-State warning starts */
    public readonly int $warningDays;

    /** 'read_only' (default) or 'allow' — what to do for a tenant with no subscription row */
    public readonly string $missingSubscription;

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

        $this->paymentProvider = $options['payment_provider'] ?? null;

        $this->adminApiKey = $options['admin_api_key']
            ?? ($env->ADMIN_API_KEY ?? null);

        $this->expiredMode = (string) ($options['expired_mode'] ?? SubscriptionMiddleware::MODE_READ_ONLY);
        $this->writeAllowList = array_values((array) ($options['write_allow_list'] ?? []));
        $this->warningDays = (int) ($options['warning_days'] ?? 7);
        $this->missingSubscription = (string) ($options['missing_subscription'] ?? 'read_only');

        // Mode-dependent defaults (see SubscriptionMiddleware::DEFAULT_EXEMPT_*).
        $this->exemptPaths = $options['exempt_paths'] ?? (
            $this->expiredMode === SubscriptionMiddleware::MODE_BLOCK
                ? SubscriptionMiddleware::DEFAULT_EXEMPT_BLOCK
                : SubscriptionMiddleware::DEFAULT_EXEMPT_READ_ONLY
        );

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
