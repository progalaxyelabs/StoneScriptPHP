<?php

declare(strict_types=1);

namespace StoneScriptPHP\Analytics;

use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Analytics\Routes\PostTrackEventRoute;

/**
 * Analytics Route Registration
 *
 * Registers the analytics event tracking route. Platforms opt in by calling
 * AnalyticsRoutes::register() in their Application::run() config.
 *
 * Usage in index.php / Application::run():
 *
 *   AnalyticsRoutes::register($router, [
 *       'enabled'    => true,
 *       'prefix'     => '/portal/analytics',  // optional, default shown
 *       'table_name' => 'analytics_events',   // optional, default shown
 *       'rate_limit' => 60,                   // events/min per IP, default shown
 *   ]);
 *
 * Schema installation:
 *   Copy (or symlink) the SQL files returned by getSchemaFiles() into your
 *   platform's src/postgresql/tenant/postgresql/tables/ and functions/
 *   directories, then run `php stone migrate up`.
 *
 *   Example:
 *     foreach (AnalyticsRoutes::getSchemaFiles()['tables'] as $file) {
 *         symlink($file, 'src/postgresql/tenant/postgresql/tables/' . basename($file));
 *     }
 *     foreach (AnalyticsRoutes::getSchemaFiles()['functions'] as $file) {
 *         symlink($file, 'src/postgresql/tenant/postgresql/functions/' . basename($file));
 *     }
 *
 * @package StoneScriptPHP\Analytics
 */
class AnalyticsRoutes
{
    /**
     * Register the analytics tracking route with the router.
     *
     * The route is registered as a public endpoint (no JWT required) so that
     * unauthenticated users (e.g. on landing pages) can still be tracked.
     * Optional auth enrichment (user_id, tenant_id) is applied when a valid
     * JWT is present in the request.
     *
     * @param Router $router  The application router instance
     * @param array  $options Configuration options (see AnalyticsConfig)
     * @return void
     */
    public static function register(Router $router, array $options = []): void
    {
        $config = new AnalyticsConfig($options);

        if (!$config->enabled) {
            log_debug('AnalyticsRoutes: disabled — skipping route registration');
            return;
        }

        $prefix = $config->prefix;

        // Public route — no JWT required; rate-limited by IP inside the handler
        $router->post(
            "$prefix/track",
            new PostTrackEventRoute($config),
            [],
            true // isPublic = true
        );

        log_debug("AnalyticsRoutes: Registered POST $prefix/track (public)");
    }

    /**
     * Return the paths this module uses — for excluding from auth middleware.
     *
     * Usage:
     *   $jwtExcludedPaths = array_merge(
     *       $jwtExcludedPaths,
     *       AnalyticsRoutes::publicPaths($options),
     *   );
     *
     * @param array $options Same options passed to register()
     * @return string[]
     */
    public static function publicPaths(array $options = []): array
    {
        $config = new AnalyticsConfig($options);
        if (!$config->enabled) {
            return [];
        }
        return ["{$config->prefix}/track"];
    }

    /**
     * Return absolute paths to the SQL schema files bundled with this module.
     *
     * Platforms that opt into analytics should copy (or symlink) these files
     * into their own postgresql/tenant/postgresql/tables/ and functions/
     * directories, then run `php stone migrate up`.
     *
     * @return array{tables: string[], functions: string[]}
     */
    public static function getSchemaFiles(): array
    {
        $schemaDir = __DIR__ . '/Schema';

        return [
            'tables' => [
                $schemaDir . '/tables/ana_001_analytics_events.pgsql',
            ],
            'functions' => [
                $schemaDir . '/functions/ana_insert_event.pgsql',
            ],
        ];
    }
}
