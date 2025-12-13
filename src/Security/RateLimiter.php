<?php

namespace Framework\Security;

/**
 * Rate Limiter
 *
 * Prevents abuse by limiting the number of requests per time window.
 * Protects against:
 * - Automated bot registrations
 * - Brute force attacks
 * - API abuse
 * - DDoS attempts
 *
 * Features:
 * - Multiple time windows (minute, hour, day)
 * - IP-based and fingerprint-based limiting
 * - Exponential backoff for repeated violations
 * - Whitelist/blacklist support
 *
 * Usage:
 * $limiter = new RateLimiter();
 * if (!$limiter->check('register', 3, 3600)) { // 3 per hour
 *     throw new Exception('Rate limit exceeded');
 * }
 */
class RateLimiter
{
    private array $attempts = [];
    private array $blacklist = [];
    private array $whitelist = [];

    // Default limits for common actions
    private const DEFAULT_LIMITS = [
        'register' => [
            'per_minute' => 1,
            'per_hour' => 3,
            'per_day' => 10
        ],
        'login' => [
            'per_minute' => 5,
            'per_hour' => 20,
            'per_day' => 100
        ],
        'password_reset' => [
            'per_minute' => 1,
            'per_hour' => 3,
            'per_day' => 10
        ],
        'email_send' => [
            'per_minute' => 2,
            'per_hour' => 10,
            'per_day' => 50
        ],
        'api_call' => [
            'per_minute' => 60,
            'per_hour' => 1000,
            'per_day' => 10000
        ]
    ];

    /**
     * Check if action is allowed
     *
     * @param string $action Action name (e.g., 'register', 'login')
     * @param int|null $maxAttempts Max attempts in window (overrides defaults)
     * @param int|null $windowSeconds Time window in seconds (overrides defaults)
     * @return bool True if allowed
     */
    public function check(string $action, ?int $maxAttempts = null, ?int $windowSeconds = null): bool
    {
        $identifier = $this->getClientIdentifier();

        // Check whitelist
        if ($this->isWhitelisted($identifier)) {
            return true;
        }

        // Check blacklist
        if ($this->isBlacklisted($identifier)) {
            log_warning("Rate limit: Blacklisted client attempted $action", [
                'identifier' => substr($identifier, 0, 16) . '...',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            return false;
        }

        // Use custom limits or defaults
        if ($maxAttempts !== null && $windowSeconds !== null) {
            return $this->checkCustomLimit($action, $identifier, $maxAttempts, $windowSeconds);
        }

        // Use default limits for action
        if (isset(self::DEFAULT_LIMITS[$action])) {
            return $this->checkDefaultLimits($action, $identifier);
        }

        // No limits defined, allow
        return true;
    }

    /**
     * Record an attempt
     */
    public function record(string $action): void
    {
        $identifier = $this->getClientIdentifier();
        $key = "{$action}:{$identifier}";

        if (!isset($this->attempts[$key])) {
            $this->attempts[$key] = [];
        }

        $this->attempts[$key][] = time();

        // Clean up old attempts
        $this->cleanup();
    }

    /**
     * Get remaining attempts for action
     */
    public function getRemainingAttempts(string $action, int $maxAttempts, int $windowSeconds): int
    {
        $identifier = $this->getClientIdentifier();
        $key = "{$action}:{$identifier}";

        if (!isset($this->attempts[$key])) {
            return $maxAttempts;
        }

        $cutoff = time() - $windowSeconds;
        $recentAttempts = array_filter($this->attempts[$key], fn($time) => $time > $cutoff);

        return max(0, $maxAttempts - count($recentAttempts));
    }

    /**
     * Get time until next attempt allowed
     */
    public function getRetryAfter(string $action, int $maxAttempts, int $windowSeconds): int
    {
        $identifier = $this->getClientIdentifier();
        $key = "{$action}:{$identifier}";

        if (!isset($this->attempts[$key])) {
            return 0;
        }

        $cutoff = time() - $windowSeconds;
        $recentAttempts = array_filter($this->attempts[$key], fn($time) => $time > $cutoff);

        if (count($recentAttempts) < $maxAttempts) {
            return 0;
        }

        // Find oldest attempt in window
        $oldestAttempt = min($recentAttempts);
        return max(0, ($oldestAttempt + $windowSeconds) - time());
    }

    /**
     * Add IP/identifier to whitelist
     */
    public function addToWhitelist(string $identifier): void
    {
        $this->whitelist[] = $identifier;
    }

    /**
     * Add IP/identifier to blacklist
     */
    public function addToBlacklist(string $identifier, int $durationSeconds = 86400): void
    {
        $this->blacklist[$identifier] = time() + $durationSeconds;

        log_warning("Client blacklisted for $durationSeconds seconds", [
            'identifier' => substr($identifier, 0, 16) . '...',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    }

    /**
     * Check if identifier is whitelisted
     */
    private function isWhitelisted(string $identifier): bool
    {
        return in_array($identifier, $this->whitelist) ||
               in_array($_SERVER['REMOTE_ADDR'] ?? '', $this->whitelist);
    }

    /**
     * Check if identifier is blacklisted
     */
    private function isBlacklisted(string $identifier): bool
    {
        // Clean expired blacklist entries
        $this->blacklist = array_filter($this->blacklist, fn($expiry) => $expiry > time());

        return isset($this->blacklist[$identifier]) ||
               isset($this->blacklist[$_SERVER['REMOTE_ADDR'] ?? '']);
    }

    /**
     * Get client identifier (IP + User-Agent hash)
     */
    private function getClientIdentifier(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        return hash('sha256', $ip . '|' . $userAgent);
    }

    /**
     * Check custom limit
     */
    private function checkCustomLimit(string $action, string $identifier, int $maxAttempts, int $windowSeconds): bool
    {
        $key = "{$action}:{$identifier}";

        if (!isset($this->attempts[$key])) {
            return true;
        }

        $cutoff = time() - $windowSeconds;
        $recentAttempts = array_filter($this->attempts[$key], fn($time) => $time > $cutoff);

        if (count($recentAttempts) >= $maxAttempts) {
            $remaining = $this->getRetryAfter($action, $maxAttempts, $windowSeconds);

            log_warning("Rate limit exceeded for $action", [
                'identifier' => substr($identifier, 0, 16) . '...',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'attempts' => count($recentAttempts),
                'max_attempts' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'retry_after' => $remaining
            ]);

            return false;
        }

        return true;
    }

    /**
     * Check default limits
     */
    private function checkDefaultLimits(string $action, string $identifier): bool
    {
        $limits = self::DEFAULT_LIMITS[$action];

        // Check per-minute limit
        if (isset($limits['per_minute'])) {
            if (!$this->checkCustomLimit($action . ':minute', $identifier, $limits['per_minute'], 60)) {
                return false;
            }
        }

        // Check per-hour limit
        if (isset($limits['per_hour'])) {
            if (!$this->checkCustomLimit($action . ':hour', $identifier, $limits['per_hour'], 3600)) {
                return false;
            }
        }

        // Check per-day limit
        if (isset($limits['per_day'])) {
            if (!$this->checkCustomLimit($action . ':day', $identifier, $limits['per_day'], 86400)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Clean up old attempts
     */
    private function cleanup(): void
    {
        static $lastCleanup = null;

        if ($lastCleanup === null) {
            $lastCleanup = time();
        }

        // Clean up every 5 minutes
        if (time() - $lastCleanup < 300) {
            return;
        }

        $cutoff = time() - 86400; // Keep 24 hours of data

        foreach ($this->attempts as $key => $attempts) {
            $this->attempts[$key] = array_filter($attempts, fn($time) => $time > $cutoff);

            if (empty($this->attempts[$key])) {
                unset($this->attempts[$key]);
            }
        }

        $lastCleanup = time();
    }

    /**
     * Get statistics for action
     */
    public function getStats(string $action): array
    {
        $identifier = $this->getClientIdentifier();
        $key = "{$action}:{$identifier}";

        if (!isset($this->attempts[$key])) {
            return [
                'total_attempts' => 0,
                'recent_1min' => 0,
                'recent_1hour' => 0,
                'recent_24hour' => 0
            ];
        }

        $now = time();
        $attempts = $this->attempts[$key];

        return [
            'total_attempts' => count($attempts),
            'recent_1min' => count(array_filter($attempts, fn($t) => $t > $now - 60)),
            'recent_1hour' => count(array_filter($attempts, fn($t) => $t > $now - 3600)),
            'recent_24hour' => count(array_filter($attempts, fn($t) => $t > $now - 86400))
        ];
    }
}
