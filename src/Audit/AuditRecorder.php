<?php

declare(strict_types=1);

namespace StoneScriptPHP\Audit;

use StoneScriptDB\GatewayClient;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Env;
use Throwable;

use function log_error;

/**
 * Writes an audit-trail record for a completed mutating request — the
 * SIMPLE, separate-audit-DB design that supersedes the SHELVED tamper-proof
 * role-split in stonescriptdb-gateway's `audit_provision/` module; do not
 * confuse the two.
 *
 * Called by Router::executeHandler() after `process()`/`execute()` returns
 * successfully. Two-tier content:
 *   1. ALWAYS: a base record (actor, tenant, platform, route, http method,
 *      result status) for every mutating request — the coverage floor, even
 *      if the route never touches the audit bag.
 *   2. OPTIONALLY: enriched with entity_type/entity_id/action/old_values/
 *      new_values/summary when the handler used {@see HasAuditBag}.
 *
 * Failure rule: the audit DB is separate (not one transaction with the
 * change) — if the audit write fails, log loudly, never silently drop
 * (no-fake-success). A broken/unreachable audit DB must NEVER fail the real
 * request it's describing — every gateway call here is try/caught and only
 * ever logged, never rethrown.
 */
final class AuditRecorder
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Test seam: when set, record() calls this closure with the built params
     * array instead of constructing a real GatewayClient and making an HTTP
     * call. Mirrors Database::fake()'s "swap the real transport for a
     * registry" shape, scaled down to this one call site. Reset with
     * fakeCallFunction(null) in tearDown().
     *
     * @var (\Closure(array<int, mixed>): void)|null
     */
    private static ?\Closure $callFunctionOverride = null;

    public static function fakeCallFunction(?\Closure $fn): void
    {
        self::$callFunctionOverride = $fn;
    }

    /**
     * @param array<string, mixed> $request Router's $request array for this
     *   dispatch (method, path, route meta — same shape executeHandler() has
     *   in hand already).
     */
    public static function record(array $request, object $handler, ApiResponse $response): void
    {
        // The ENTIRE thing — including buildParams() itself, not just the
        // gateway call — must be exception-safe. buildParams() calls
        // Env::get_instance(), which throws if required gateway config is
        // missing; a broken/misconfigured audit setup must be no more able
        // to break the real request than a broken audit gateway is. Caught
        // this exact gap via a real regression: a fresh Env singleton
        // reconstruction mid-test-suite (unrelated required env var
        // temporarily unset) turned an otherwise-successful 'ok' response
        // into a 500, because the throw happened outside the try/catch.
        try {
            $params = self::buildParams($request, $handler, $response);
            if ($params === null) {
                return;
            }

            if (self::$callFunctionOverride !== null) {
                (self::$callFunctionOverride)($params);
                return;
            }

            $env = Env::get_instance();
            $client = new GatewayClient($env->DB_GATEWAY_URL, $env->DB_GATEWAY_PLATFORM, 'audit');
            $client->callFunction('audit_append', $params);
        } catch (Throwable $e) {
            // No-fake-success: log loudly, never throw. See class docblock.
            log_error('AuditRecorder: failed to write audit record for '
                . ($request['method'] ?? '?') . ' ' . ($request['path'] ?? '?') . ': ' . $e->getMessage());
        }
    }

    /**
     * Pure builder: the positional param list `audit_append()` expects, or
     * null when this request shouldn't be audited at all (non-mutating
     * method, or the platform hasn't opted in via AUDIT_TRAIL_ENABLED).
     * Exposed separately so tests can assert on exactly what would be sent
     * without a live gateway.
     *
     * Positional order MUST match
     * stonescriptdb-gateway's src/audit/mod.rs `audit_append(...)` signature:
     * tenant_id, actor_identity_id, platform_code, route, http_method,
     * action, result_status, entity_type, entity_id, old_values, new_values,
     * summary.
     *
     * @param array<string, mixed> $request
     * @return array<int, mixed>|null
     */
    public static function buildParams(array $request, object $handler, ApiResponse $response): ?array
    {
        $method = strtoupper((string) ($request['method'] ?? ''));
        if (!in_array($method, self::MUTATING_METHODS, true)) {
            return null;
        }

        $env = Env::get_instance();
        if (!$env->AUDIT_TRAIL_ENABLED) {
            return null;
        }

        $bag = self::readAuditBag($handler);
        $actorIdentityId = AuthContext::check() ? AuthContext::id() : null;
        $tenantId = AuthContext::check() ? (AuthContext::getUser()?->tenant_id ?? null) : null;
        $route = $request['route']['pattern'] ?? ($request['path'] ?? '');
        // PLATFORM_CODE is optional and frequently left unset (confirmed live:
        // a real deployment had it blank) -- DB_GATEWAY_PLATFORM is the one
        // Env actually requires non-empty under DB_MODE=gateway (the
        // default), so it's the reliable fallback rather than writing every
        // record with an empty platform_code.
        $platformCode = $env->PLATFORM_CODE !== '' ? $env->PLATFORM_CODE : $env->DB_GATEWAY_PLATFORM;

        return [
            $tenantId,
            $actorIdentityId,
            $platformCode,
            $route,
            $method,
            $bag['action'] ?? null,
            $response->httpStatusCode ?? 200,
            $bag['entity_type'] ?? null,
            $bag['entity_id'] ?? null,
            isset($bag['old_values']) ? json_encode($bag['old_values']) : null,
            isset($bag['new_values']) ? json_encode($bag['new_values']) : null,
            $bag['summary'] ?? null,
        ];
    }

    /**
     * Duck-typed, not interface-typed — see {@see HasAuditBag}'s docblock
     * for why. A handler that never used the trait (the overwhelming
     * majority, always, for platforms not using this feature) simply has no
     * `auditBag()` method, and this returns an empty array — the base record
     * still gets written, just with no enrichment.
     *
     * @return array{action?: ?string, entity_type?: ?string, entity_id?: ?string, old_values?: ?array, new_values?: ?array, summary?: ?string}
     */
    private static function readAuditBag(object $handler): array
    {
        if (!method_exists($handler, 'auditBag')) {
            return [];
        }

        $bag = $handler->auditBag();
        return is_array($bag) ? $bag : [];
    }
}
