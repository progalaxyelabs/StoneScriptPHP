<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

/**
 * Platform Token Service
 *
 * Manages platform JWT lifecycle in Redis for instant invalidation.
 * Platform JWTs are issued by token exchange (the platform API's /api/auth/exchange).
 * Stored in Redis keyed by identity_id; deleting the key forces re-exchange on next request.
 *
 * Usage:
 *   $svc = PlatformTokenService::fromEnv();   // reads REDIS_URL from env
 *   $svc->store($identityId, $jwtString, $ttlSeconds);
 *   $svc->isValid($identityId, $jwtString);   // false if key deleted = role changed
 *   $svc->revoke($identityId);                // role change / account deletion
 */
class PlatformTokenService
{
    private ?\Redis $redis = null;
    private string $keyPrefix;

    public function __construct(
        private string $redisUrl,
        string $keyPrefix = 'platform_token:'
    ) {
        $this->keyPrefix = $keyPrefix;
    }

    public static function fromEnv(): self
    {
        $env = \StoneScriptPHP\Env::get_instance();
        $url = $env->PLATFORM_REDIS_URL ?? $env->REDIS_URL ?? 'redis://localhost:6379';
        return new self($url);
    }

    /**
     * Store a platform JWT in Redis with TTL matching the token expiry.
     */
    public function store(string $identityId, string $token, int $ttlSeconds): void
    {
        $this->redis()->setex($this->key($identityId), $ttlSeconds, $token);
    }

    /**
     * Check if the given token is the current valid token for this identity.
     * Returns false if the key is missing (revoked) or token doesn't match.
     */
    public function isValid(string $identityId, string $token): bool
    {
        $stored = $this->redis()->get($this->key($identityId));
        return $stored !== false && hash_equals($stored, $token);
    }

    /**
     * Revoke the platform JWT for an identity (role change, account deletion).
     * Next request will get 401 → client re-exchanges for fresh token with correct roles.
     */
    public function revoke(string $identityId): void
    {
        $this->redis()->del($this->key($identityId));
    }

    private function key(string $identityId): string
    {
        return $this->keyPrefix . $identityId;
    }

    private function redis(): \Redis
    {
        if ($this->redis === null) {
            $parsed = parse_url($this->redisUrl);
            $host   = $parsed['host'] ?? '127.0.0.1';
            $port   = $parsed['port'] ?? 6379;
            $pass   = isset($parsed['pass']) ? urldecode($parsed['pass']) : null;

            $r = new \Redis();
            $r->connect($host, (int) $port, 2.0);
            if ($pass) $r->auth($pass);
            $this->redis = $r;
        }
        return $this->redis;
    }
}
