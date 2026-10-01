<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Database;
use StoneScriptPHP\Routing\MiddlewareInterface;

/**
 * Subscription enforcement middleware (v10: read-only by default).
 *
 * Reads the tenant's status from the platform's main database via
 * `sub_get_status()` and applies one of two enforcement modes when the
 * subscription (or trial) is expired / inactive:
 *
 *  - `read_only` (DEFAULT) — GET/HEAD/OPTIONS pass; every other method is
 *    refused with HTTP 423 and a stable `data.error_code`
 *    (READ_ONLY_TRIAL_EXPIRED | READ_ONLY_PLAN_ENDED | READ_ONLY_NO_SUBSCRIPTION),
 *    except for paths on the write allow-list (auth, account deletion/cancel,
 *    subscription routes, ... — see DEFAULT_WRITE_ALLOW_LIST). A customer's
 *    data is never held hostage and the right to erasure always works.
 *  - `block` — the legacy lockout: every non-exempt path gets HTTP 402.
 *
 * Every authenticated, non-exempt response also carries an
 * `X-Subscription-State` header so clients can show a banner without an
 * extra call:
 *     ok
 *     trial_ending; ends_at=2026-10-10T00:00:00Z; days=3
 *     plan_ending;  ends_at=...; days=N
 *     read_only; ended_at=2026-09-10T00:00:00Z; reason=trial_expired
 *
 * Failure policy:
 *  - DB/gateway error → fail OPEN (no header, request proceeds).
 *  - No subscription row → treated like an expired subscription (read-only,
 *    or 402 in `block` mode): reads still work, writes are refused. Opt out
 *    with `missing_subscription => 'allow'`.
 *
 * Must be registered AFTER JwtAuthMiddleware so auth() has a tenant_id.
 *
 * @package StoneScriptPHP\Subscriptions
 */
class SubscriptionMiddleware implements MiddlewareInterface
{
    public const MODE_READ_ONLY = 'read_only';
    public const MODE_BLOCK     = 'block';

    public const HEADER_NAME = 'X-Subscription-State';

    /** Always-writable paths in read_only mode; config can add to, never remove from, these. */
    public const DEFAULT_WRITE_ALLOW_LIST = [
        '/health',
        '/auth',
        '/account',
        '/subscription',
        '/export',
        '/internal',
    ];

    /** Exempt (no lookup, no header) defaults per mode. */
    public const DEFAULT_EXEMPT_READ_ONLY = ['/health', '/auth/'];
    public const DEFAULT_EXEMPT_BLOCK     = ['/health', '/auth/', '/subscription/status', '/account/', '/export'];

    private string $expiredMode;
    private array $exemptPaths;
    private array $writeAllowList;
    private int $warningDays;
    private bool $allowMissingSubscription;
    /** @var callable(string): ?array */
    private $statusProvider;
    /** @var callable(): \DateTimeImmutable */
    private $clock;
    private bool $customProviderGiven;

    /**
     * @param string        $expiredMode              'read_only' (default) or 'block' (legacy 402 lockout).
     * @param array|null    $exemptPaths              Skip enforcement AND the header entirely. Trailing slash =
     *                                                prefix match; no slash = exact or path-component prefix.
     *                                                null = mode default.
     * @param array         $writeAllowList           EXTRA write-allowed paths, merged with DEFAULT_WRITE_ALLOW_LIST.
     *                                                An entry may be prefixed with a method ("POST /devices/pair").
     * @param int           $warningDays              Days before expiry the warning state starts (default 7).
     * @param string        $missingSubscription      'read_only' (default; follows expiredMode) or 'allow'.
     * @param callable|null $statusProvider           fn(string $tenantId): ?array — decoded sub_get_status row, null
     *                                                for no row; throw to signal a lookup failure (fail open).
     *                                                Default queries the main DB through the gateway.
     * @param callable|null $clock                    fn(): DateTimeImmutable (test seam).
     */
    public function __construct(
        string $expiredMode = self::MODE_READ_ONLY,
        ?array $exemptPaths = null,
        array $writeAllowList = [],
        int $warningDays = 7,
        string $missingSubscription = 'read_only',
        ?callable $statusProvider = null,
        ?callable $clock = null,
    ) {
        if (!in_array($expiredMode, [self::MODE_READ_ONLY, self::MODE_BLOCK], true)) {
            throw new \InvalidArgumentException(
                "SubscriptionMiddleware: expired_mode must be 'read_only' or 'block', got '{$expiredMode}'"
            );
        }
        if (!in_array($missingSubscription, ['read_only', 'allow'], true)) {
            throw new \InvalidArgumentException(
                "SubscriptionMiddleware: missing_subscription must be 'read_only' or 'allow', got '{$missingSubscription}'"
            );
        }

        $this->expiredMode              = $expiredMode;
        $this->exemptPaths              = $exemptPaths
            ?? ($expiredMode === self::MODE_BLOCK ? self::DEFAULT_EXEMPT_BLOCK : self::DEFAULT_EXEMPT_READ_ONLY);
        $this->writeAllowList           = array_values(array_unique(array_merge(self::DEFAULT_WRITE_ALLOW_LIST, $writeAllowList)));
        $this->warningDays              = $warningDays;
        $this->allowMissingSubscription = $missingSubscription === 'allow';
        $this->customProviderGiven      = $statusProvider !== null;
        $this->statusProvider           = $statusProvider ?? fn(string $t): ?array => $this->queryGateway($t);
        $this->clock                    = $clock ?? fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** Build from the `subscription` application config (see SubscriptionConfig). */
    public static function fromConfig(SubscriptionConfig $config): self
    {
        return new self(
            expiredMode: $config->expiredMode,
            exemptPaths: $config->exemptPaths,
            writeAllowList: $config->writeAllowList,
            warningDays: $config->warningDays,
            missingSubscription: $config->missingSubscription,
        );
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        $path = $this->extractPath();

        if ($this->matchesAny($path, $this->exemptPaths)) {
            return $next($request);
        }

        // Only enforce for authenticated users — let auth middleware handle unauthenticated
        $user = auth();
        if (!$user || !($user->tenant_id ?? null)) {
            return $next($request);
        }

        // Billing is a gateway-mode-only concept; a custom statusProvider
        // (tests, or an app with its own source) bypasses the check.
        if (!$this->customProviderGiven && !Database::isGatewayMode()) {
            return $next($request);
        }

        $tenantId = (string) $user->tenant_id;

        try {
            $row = ($this->statusProvider)($tenantId);
        } catch (\Throwable $e) {
            // Fail OPEN on lookup errors — never block users on infra trouble.
            error_log('[SubscriptionMiddleware] lookup failed for tenant=' . $tenantId . ': ' . $e->getMessage());
            return $next($request);
        }

        if ($row === null && $this->allowMissingSubscription) {
            return $next($request);
        }

        $state = SubscriptionState::fromRow($row, ($this->clock)(), $this->warningDays);
        $this->sendHeader(self::HEADER_NAME, $state->toHeaderValue());

        if (!$state->isReadOnly()) {
            return $next($request);
        }

        // ----- expired / inactive / no row -----
        if ($this->expiredMode === self::MODE_BLOCK) {
            error_log('[SubscriptionMiddleware] Blocked tenant=' . $tenantId . ' — ' . ($state->reason ?? 'inactive'));
            return new ApiResponse(
                'error',
                'Your subscription has expired. Please renew to continue using this service.',
                ['error_code' => 'SUBSCRIPTION_EXPIRED'],
                402,
            );
        }

        $method = strtoupper((string) ($request['method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) || $this->isWriteAllowed($method, $path)) {
            return $next($request);
        }

        return new ApiResponse(
            'error',
            'This account is read-only because its subscription has ended. You can still view your data.',
            $state->toRefusalData(),
            423,
        );
    }

    /** Overridable seam so tests can capture headers (header() is a no-op once PHPUnit output started). */
    protected function sendHeader(string $name, string $value): void
    {
        if (!headers_sent()) {
            header($name . ': ' . $value);
        }
    }

    private function isWriteAllowed(string $method, string $path): bool
    {
        foreach ($this->writeAllowList as $entry) {
            $entry = trim((string) $entry);
            if (preg_match('/^([A-Za-z]+)\s+(\/.*)$/', $entry, $m)) {
                if (strtoupper($m[1]) !== $method) {
                    continue;
                }
                $entry = $m[2];
            }
            if ($this->matchesAny($path, [$entry])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Match $path (and $path with a leading /api segment stripped, so config
     * written without the prefix works for both) against prefix/exact entries.
     */
    private function matchesAny(string $path, array $entries): bool
    {
        $candidates = [$path];
        $stripped = preg_replace('#^/api(?=/|$)#', '', $path);
        if ($stripped !== null && $stripped !== $path) {
            $candidates[] = $stripped === '' ? '/' : $stripped;
        }

        foreach ($candidates as $p) {
            foreach ($entries as $entry) {
                if (str_ends_with($entry, '/')) {
                    if ($p === rtrim($entry, '/') || str_starts_with($p, $entry)) {
                        return true;
                    }
                } elseif ($p === $entry || str_starts_with($p, $entry . '/')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Default provider: main-DB `sub_get_status()` through the gateway.
     *
     * @return array|null decoded row, null when the tenant has no subscription row
     * @throws \Throwable on lookup failure (caller fails open)
     */
    private function queryGateway(string $tenantId): ?array
    {
        $gw = Database::getGatewayClient();
        $prevTenant = $gw->getTenantId();
        $gw->setTenantId(null); // always the main DB

        try {
            $result = Database::fn('sub_get_status', [$tenantId]);
        } finally {
            $gw->setTenantId($prevTenant);
        }

        $data = $result[0] ?? null;
        if (is_object($data)) {
            $data = (array) $data;
        }
        // Gateway pre-decodes JSON — handle both string and array forms
        if (is_array($data) && array_key_exists('sub_get_status', $data)) {
            $data = is_string($data['sub_get_status'])
                ? json_decode($data['sub_get_status'], true)
                : $data['sub_get_status'];
        }
        if (is_object($data)) {
            $data = (array) $data;
        }

        return is_array($data) && $data !== [] ? $data : null;
    }

    private function extractPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return parse_url($uri, PHP_URL_PATH) ?? '/';
    }
}
