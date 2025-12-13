<?php

namespace StoneScriptPHP\Security;

/**
 * CSRF Token Handler
 *
 * Generates and validates CSRF tokens for public routes to prevent
 * automated spam, bot registrations, and CSRF attacks.
 *
 * Token Format: {timestamp}.{client_fingerprint}.{hmac_signature}
 *
 * Features:
 * - Time-based expiration (default: 1 hour)
 * - Client fingerprint binding (IP + User-Agent hash)
 * - HMAC signature for integrity
 * - Single-use tokens (via nonce tracking)
 * - Rate limiting per client
 */
class CsrfTokenHandler
{
    private const TOKEN_EXPIRY = 3600; // 1 hour
    private const MAX_TOKENS_PER_CLIENT = 10; // Max active tokens per client

    private string $secretKey;
    private array $usedNonces = [];
    private array $clientTokenCounts = [];

    public function __construct(?string $secretKey = null)
    {
        $this->secretKey = $secretKey ?? $this->getSecretKey();
    }

    /**
     * Generate a new CSRF token
     *
     * @param array $context Additional context (e.g., action='register')
     * @return string CSRF token
     */
    public function generateToken(array $context = []): string
    {
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $clientFingerprint = $this->getClientFingerprint();
        $action = $context['action'] ?? 'general';

        // Check rate limit
        if ($this->isRateLimited($clientFingerprint)) {
            log_warning("CSRF token generation rate limited", [
                'client_fingerprint' => substr($clientFingerprint, 0, 16) . '...',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            throw new \RuntimeException('Too many token requests. Please try again later.');
        }

        // Create token payload
        $payload = json_encode([
            'ts' => $timestamp,
            'nonce' => $nonce,
            'fp' => $clientFingerprint,
            'action' => $action
        ]);

        $encodedPayload = base64_encode($payload);
        $signature = $this->createSignature($encodedPayload);

        // Track token generation
        $this->trackTokenGeneration($clientFingerprint);

        return "{$encodedPayload}.{$signature}";
    }

    /**
     * Validate a CSRF token
     *
     * @param string $token CSRF token to validate
     * @param array $context Validation context (e.g., action='register')
     * @return bool True if valid
     */
    public function validateToken(string $token, array $context = []): bool
    {
        if (empty($token)) {
            log_warning("CSRF validation failed: Empty token");
            return false;
        }

        // Parse token
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            log_warning("CSRF validation failed: Invalid token format");
            return false;
        }

        [$encodedPayload, $signature] = $parts;

        // Verify signature
        if (!$this->verifySignature($encodedPayload, $signature)) {
            log_warning("CSRF validation failed: Invalid signature");
            return false;
        }

        // Decode payload
        $payload = json_decode(base64_decode($encodedPayload), true);
        if (!$payload) {
            log_warning("CSRF validation failed: Invalid payload");
            return false;
        }

        // Check required fields
        if (!isset($payload['ts'], $payload['nonce'], $payload['fp'])) {
            log_warning("CSRF validation failed: Missing fields");
            return false;
        }

        // Check expiration
        if (time() - $payload['ts'] > self::TOKEN_EXPIRY) {
            log_notice("CSRF validation failed: Token expired", [
                'age_seconds' => time() - $payload['ts']
            ]);
            return false;
        }

        // Check client fingerprint
        $currentFingerprint = $this->getClientFingerprint();
        if (!hash_equals($payload['fp'], $currentFingerprint)) {
            log_warning("CSRF validation failed: Fingerprint mismatch", [
                'expected' => substr($payload['fp'], 0, 16) . '...',
                'actual' => substr($currentFingerprint, 0, 16) . '...'
            ]);
            return false;
        }

        // Check action if specified
        if (isset($context['action']) && $payload['action'] !== $context['action']) {
            log_warning("CSRF validation failed: Action mismatch", [
                'expected' => $context['action'],
                'actual' => $payload['action']
            ]);
            return false;
        }

        // Check nonce (single-use)
        if ($this->isNonceUsed($payload['nonce'])) {
            log_warning("CSRF validation failed: Token already used", [
                'nonce' => substr($payload['nonce'], 0, 8) . '...'
            ]);
            return false;
        }

        // Mark nonce as used
        $this->markNonceUsed($payload['nonce']);

        log_debug("CSRF token validated successfully", [
            'action' => $payload['action'],
            'age_seconds' => time() - $payload['ts']
        ]);

        return true;
    }

    /**
     * Get client fingerprint based on IP and User-Agent
     */
    private function getClientFingerprint(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        // Use first 3 octets of IP to handle dynamic IPs from same network
        $ipParts = explode('.', $ip);
        $ipPrefix = implode('.', array_slice($ipParts, 0, 3));

        return hash('sha256', $ipPrefix . '|' . $userAgent . '|' . $this->secretKey);
    }

    /**
     * Create HMAC signature for payload
     */
    private function createSignature(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secretKey);
    }

    /**
     * Verify HMAC signature
     */
    private function verifySignature(string $payload, string $signature): bool
    {
        $expected = $this->createSignature($payload);
        return hash_equals($expected, $signature);
    }

    /**
     * Get secret key from environment or generate one
     */
    private function getSecretKey(): string
    {
        $key = getenv('CSRF_SECRET_KEY');

        if (!$key) {
            // In production, this should be set in environment
            log_warning("CSRF_SECRET_KEY not set in environment. Using generated key (not persistent across restarts).");
            $key = bin2hex(random_bytes(32));
        }

        return $key;
    }

    /**
     * Check if client is rate limited
     */
    private function isRateLimited(string $clientFingerprint): bool
    {
        // Clean up old entries
        $this->cleanupTokenTracking();

        if (!isset($this->clientTokenCounts[$clientFingerprint])) {
            return false;
        }

        return $this->clientTokenCounts[$clientFingerprint] >= self::MAX_TOKENS_PER_CLIENT;
    }

    /**
     * Track token generation for rate limiting
     */
    private function trackTokenGeneration(string $clientFingerprint): void
    {
        if (!isset($this->clientTokenCounts[$clientFingerprint])) {
            $this->clientTokenCounts[$clientFingerprint] = 0;
        }

        $this->clientTokenCounts[$clientFingerprint]++;
    }

    /**
     * Check if nonce has been used
     */
    private function isNonceUsed(string $nonce): bool
    {
        // Clean up expired nonces
        $this->cleanupUsedNonces();

        return isset($this->usedNonces[$nonce]);
    }

    /**
     * Mark nonce as used
     */
    private function markNonceUsed(string $nonce): void
    {
        $this->usedNonces[$nonce] = time();
    }

    /**
     * Clean up expired nonces
     */
    private function cleanupUsedNonces(): void
    {
        $cutoff = time() - self::TOKEN_EXPIRY;

        foreach ($this->usedNonces as $nonce => $timestamp) {
            if ($timestamp < $cutoff) {
                unset($this->usedNonces[$nonce]);
            }
        }
    }

    /**
     * Clean up token tracking
     */
    private function cleanupTokenTracking(): void
    {
        // Reset counts periodically (every hour)
        // In production, this should use a persistent store (Redis, database)
        static $lastCleanup = null;

        if ($lastCleanup === null) {
            $lastCleanup = time();
        }

        if (time() - $lastCleanup > 3600) {
            $this->clientTokenCounts = [];
            $lastCleanup = time();
        }
    }
}
