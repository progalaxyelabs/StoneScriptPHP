<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions;

use StoneScriptPHP\Router;
use StoneScriptPHP\Subscriptions\Routes\GetSubscriptionStatusRoute;
use StoneScriptPHP\Subscriptions\Routes\GetSubscriptionPlansRoute;
use StoneScriptPHP\Subscriptions\Routes\PostAdminActivateRoute;
use StoneScriptPHP\Subscriptions\Routes\PostRazorpayWebhookRoute;

/**
 * Subscription Route Registration
 *
 * Registers framework-level subscription routes. Platforms opt in by calling
 * SubscriptionRoutes::register() from their index.php.
 *
 * Usage in index.php:
 *
 *   SubscriptionRoutes::register($router, [
 *       'platform_code'           => 'medstoreapp',
 *       'razorpay_webhook_secret' => $_ENV['RAZORPAY_WEBHOOK_SECRET'], // opt-in
 *       'admin_api_key'           => $_ENV['ADMIN_API_KEY'],           // opt-in
 *       'prefix'                  => '/subscription',                  // default
 *   ]);
 *
 *   // Exclude public subscription paths from JWT middleware:
 *   $jwtMiddleware = new JwtAuthMiddleware([
 *       'excludedPaths' => array_merge(
 *           ExternalAuthRoutes::publicPaths($authOptions),
 *           SubscriptionRoutes::publicPaths($subOptions),
 *       ),
 *   ]);
 *
 *   // Enforce subscriptions after auth:
 *   $router->addMiddleware(new SubscriptionMiddleware(
 *       exempt_paths: SubscriptionRoutes::publicPaths($subOptions),
 *   ));
 *
 * Schema installation:
 *   Copy (or symlink) the SQL files returned by getSchemaFiles() into your
 *   platform's src/postgresql/main/postgresql/tables/ and functions/ directories,
 *   then run `php stone migrate up`.
 *
 * @package StoneScriptPHP\Subscriptions
 */
class SubscriptionRoutes
{
    /**
     * Register all enabled subscription routes with the router.
     *
     * @param Router $router The router instance
     * @param array $options Configuration options (see SubscriptionConfig)
     * @return void
     */
    public static function register(Router $router, array $options = []): void
    {
        $config = new SubscriptionConfig($options);
        $prefix = $config->prefix;

        // Public routes (no JWT required)
        if ($config->isEnabled('razorpay_webhook')) {
            $router->post("$prefix/webhook/razorpay", new PostRazorpayWebhookRoute($config), [], true);
            log_debug("SubscriptionRoutes: Registered POST $prefix/webhook/razorpay (public)");
        }

        if ($config->isEnabled('admin_activate')) {
            $router->post("$prefix/admin/activate", new PostAdminActivateRoute($config), [], true);
            log_debug("SubscriptionRoutes: Registered POST $prefix/admin/activate (X-Admin-Key)");
        }

        // Protected routes (JWT required)
        if ($config->isEnabled('status')) {
            $router->get("$prefix/status", new GetSubscriptionStatusRoute($config));
            log_debug("SubscriptionRoutes: Registered GET $prefix/status (protected)");
        }

        if ($config->isEnabled('plans')) {
            $router->get("$prefix/plans", new GetSubscriptionPlansRoute($config));
            log_debug("SubscriptionRoutes: Registered GET $prefix/plans (protected)");
        }

        log_info("SubscriptionRoutes: Registration complete with prefix '$prefix'");
    }

    /**
     * Get public paths (no JWT required) based on options.
     *
     * Pure function — computes paths WITHOUT registering routes.
     * Use this to build JwtAuthMiddleware excludedPaths.
     *
     * @param array $options Same options passed to register()
     * @return array List of public path strings
     */
    public static function publicPaths(array $options = []): array
    {
        $prefix = rtrim($options['prefix'] ?? '/subscription', '/');
        $paths = [];

        // Determine which public routes are enabled (mirror SubscriptionConfig logic)
        $hasWebhookSecret = !empty($options['razorpay_webhook_secret'])
            || !empty($options['razorpay_webhook']);
        $hasAdminKey = !empty($options['admin_api_key'])
            || !empty($options['admin_activate']);

        $webhookEnabled = $options['razorpay_webhook'] ?? $hasWebhookSecret;
        $adminEnabled   = $options['admin_activate']   ?? $hasAdminKey;

        if ($webhookEnabled) {
            $paths[] = "$prefix/webhook/razorpay";
        }
        if ($adminEnabled) {
            $paths[] = "$prefix/admin/activate";
        }

        return $paths;
    }

    /**
     * Get route definitions in the same format as routes.php.
     *
     * Returns ['GET' => ['/path' => HandlerClass::class], 'POST' => [...], ...]
     * Used by the client generator to include framework-level routes.
     *
     * Does NOT instantiate SubscriptionConfig (which may require Env/DB).
     * Reads prefix and feature toggles directly from the options array.
     *
     * @param array $options Same options passed to register()
     * @return array Route definitions grouped by HTTP method
     */
    public static function getRouteDefinitions(array $options = []): array
    {
        $prefix = rtrim($options['prefix'] ?? '/subscription', '/');

        $hasWebhookSecret = !empty($options['razorpay_webhook_secret']);
        $hasAdminKey = !empty($options['admin_api_key']);

        $features = [
            'status'           => $options['status'] ?? true,
            'plans'            => $options['plans'] ?? true,
            'razorpay_webhook' => $options['razorpay_webhook'] ?? $hasWebhookSecret,
            'admin_activate'   => $options['admin_activate'] ?? $hasAdminKey,
        ];

        $isEnabled = fn(string $feature) => $features[$feature] ?? false;
        $routes = ['GET' => [], 'POST' => []];

        if ($isEnabled('razorpay_webhook')) {
            $routes['POST']["$prefix/webhook/razorpay"] = PostRazorpayWebhookRoute::class;
        }
        if ($isEnabled('admin_activate')) {
            $routes['POST']["$prefix/admin/activate"] = PostAdminActivateRoute::class;
        }
        if ($isEnabled('status')) {
            $routes['GET']["$prefix/status"] = GetSubscriptionStatusRoute::class;
        }
        if ($isEnabled('plans')) {
            $routes['GET']["$prefix/plans"] = GetSubscriptionPlansRoute::class;
        }

        return $routes;
    }

    /**
     * Get absolute paths to the SQL schema files bundled with this module.
     *
     * Platforms that opt into the subscription module should copy (or symlink) these
     * files into their own postgresql/main/postgresql/tables/ and functions/ directories,
     * then run `php stone migrate up`.
     *
     * @return array{tables: string[], functions: string[]} Grouped by type
     */
    public static function getSchemaFiles(): array
    {
        $schemaDir = __DIR__ . '/Schema';

        return [
            'tables' => [
                $schemaDir . '/tables/sub_010_subscription_plans.pgsql',
                $schemaDir . '/tables/sub_011_subscriptions.pgsql',
                $schemaDir . '/tables/sub_012_subscription_payments.pgsql',
            ],
            'functions' => [
                $schemaDir . '/functions/sub_get_status.pgsql',
                $schemaDir . '/functions/sub_activate.pgsql',
                $schemaDir . '/functions/sub_list_plans.pgsql',
                $schemaDir . '/functions/sub_find_by_email.pgsql',
                $schemaDir . '/functions/sub_get_plan.pgsql',
            ],
        ];
    }
}
