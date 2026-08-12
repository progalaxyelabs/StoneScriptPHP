<?php

declare(strict_types=1);

namespace StoneScriptPHP\Plugin;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\Tenancy\TenancyStrategyInterface;

/**
 * PluginInterface
 *
 * Phase 1 extensibility seam (non-breaking, additive). A plugin is a unit of
 * optional functionality — e.g. a future multi-tenancy plugin extracted out of
 * core — that a platform opts into via `Application::run(['plugins' => [...]])`.
 *
 * ## Wiring convention
 *
 * A platform lists its plugins in `src/config/plugins.php` (same convention as
 * `routes.php`/`auth.php`), returning an array of PluginInterface instances:
 *
 *   // src/config/plugins.php
 *   return [
 *       new \Some\Vendor\MultiTenancyPlugin(),
 *   ];
 *
 * and passes it through in `public/index.php`:
 *
 *   Application::run([
 *       'routes'  => require CONFIG_PATH . 'routes.php',
 *       'plugins' => require CONFIG_PATH . 'plugins.php',
 *       // ...
 *   ]);
 *
 * ## Non-breaking guarantee
 *
 * `Application::run()` treats `$config['plugins']` as `?? []`. With no plugins
 * configured (the default for every platform today — none of the 11 fleet
 * platforms pass this key yet), every contribution point below returns nothing
 * and behavior is byte-for-byte identical to pre-plugin StoneScriptPHP.
 *
 * ## Precedence
 *
 * - Plugin middleware is appended AFTER the platform's own `config['middleware']`
 *   and BEFORE `require_api_token`/`tenant_url_match` enforcement — a plugin cannot
 *   silently skip a platform's own middleware ordering guarantees.
 * - Plugin routes are merged UNDER the platform's own `routes.php` entries: if a
 *   platform defines the same METHOD+path as a plugin, the platform's own route
 *   wins. A plugin can offer defaults but never override explicit platform config.
 *
 * Prefer extending `AbstractPlugin` over implementing this interface directly —
 * it supplies safe no-op defaults for every method so a plugin only needs to
 * override what it actually contributes.
 *
 * @package StoneScriptPHP\Plugin
 */
interface PluginInterface
{
    /**
     * Short, unique, human-readable plugin identifier (used only in logs/errors).
     */
    public function name(): string;

    /**
     * Additional global middleware this plugin contributes.
     *
     * @return MiddlewareInterface[]
     */
    public function middleware(): array;

    /**
     * Additional routes this plugin contributes, in the same flat `routes.php`
     * format Router::loadRoutes() consumes:
     *
     *   ['GET' => ['/path' => ['handler' => X::class, 'group' => 'g', ...]], 'POST' => [...]]
     *
     * @return array<string, array<string, array>>
     */
    public function routes(): array;

    /**
     * Absolute directory paths containing `*.sql` migration files this plugin
     * contributes, consumed additively by `Migrations::scanMigrationFiles()`
     * (via `Migrations::addMigrationPath()`) alongside the platform's own
     * `ROOT_PATH/migrations/` directory.
     *
     * @return string[]
     */
    public function migrationPaths(): array;

    /**
     * Absolute directory paths for schema-drift scanning this plugin
     * contributes, consumed additively by `Migrations::verify()` (via
     * `Migrations::addSchemaPath()`) alongside the platform's own
     * `src/App/Database/postgresql/{tables,functions}/` directories.
     *
     * @return array{tables?: string[], functions?: string[]}
     */
    public function schemaPaths(): array;

    /**
     * Optional tenancy resolution strategy this plugin registers. Returning
     * null (the default via AbstractPlugin) means this plugin has no opinion
     * on tenancy — `GatewayTenantMiddleware` keeps using `NoTenantStrategy`
     * (today's behavior) unless a platform or another plugin supplies one.
     */
    public function tenancyStrategy(): ?TenancyStrategyInterface;
}
