<?php

namespace StoneScriptPHP\Security;

/**
 * Proof-of-Work Challenge System
 *
 * Prevents automated bot registrations by requiring clients to solve
 * a computational puzzle before submitting forms.
 *
 * How it works:
 * 1. Client requests challenge
 * 2. Server generates: challenge string + difficulty level
 * 3. Client must find nonce where: SHA256(nonce + challenge) starts with N zeros
 * 4. Client submits solution with request
 * 5. Server verifies solution (instant)
 *
 * Benefits:
 * - Invisible to users (1-5 second delay)
 * - Makes mass automation expensive (CPU time)
 * - No third-party services required
 * - No CAPTCHA friction
 *
 * Difficulty levels:
 * - 3: Very easy (~0.1-1 seconds) - Development/testing
 * - 4: Easy (~1-5 seconds) - Recommended for production
 * - 5: Medium (~5-30 seconds) - High security
 * - 6: Hard (~30-120 seconds) - Maximum security
 */
class ProofOfWorkChallenge
{
    private const DEFAULT_DIFFICULTY = 4;
    private const CHALLENGE_EXPIRY = 300; // 5 minutes

    /**
     * Generate a new challenge
     *
     * @param int $difficulty Number of leading zeros required (3-6)
     * @return array ['challenge' => string, 'difficulty' => int, 'expires_at' => int]
     */
    public function generateChallenge(int $difficulty = self::DEFAULT_DIFFICULTY): array
    {
        if ($difficulty < 3 || $difficulty > 6) {
            throw new \InvalidArgumentException('Difficulty must be between 3 and 6');
        }

        $challenge = bin2hex(random_bytes(16));
        $expiresAt = time() + self::CHALLENGE_EXPIRY;

        log_debug("PoW challenge generated", [
            'challenge' => substr($challenge, 0, 16) . '...',
            'difficulty' => $difficulty,
            'expires_at' => $expiresAt
        ]);

        return [
            'challenge' => $challenge,
            'difficulty' => $difficulty,
            'expires_at' => $expiresAt
        ];
    }

    /**
     * Verify proof-of-work solution
     *
     * @param string $challenge Original challenge string
     * @param int $nonce Client-provided nonce
     * @param int $difficulty Expected difficulty level
     * @param int $expiresAt Challenge expiration timestamp
     * @return bool True if solution is valid
     */
    public function verifySolution(
        string $challenge,
        int $nonce,
        int $difficulty,
        int $expiresAt
    ): bool {
        // Check expiration
        if (time() > $expiresAt) {
            log_notice("PoW verification failed: Challenge expired", [
                'expired_at' => $expiresAt,
                'current_time' => time()
            ]);
            return false;
        }

        // Compute hash
        $hash = hash('sha256', $nonce . $challenge);

        // Check if hash has required leading zeros
        $requiredPrefix = str_repeat('0', $difficulty);
        $valid = str_starts_with($hash, $requiredPrefix);

        if ($valid) {
            log_debug("PoW verification successful", [
                'nonce' => $nonce,
                'hash' => substr($hash, 0, 16) . '...',
                'difficulty' => $difficulty
            ]);
        } else {
            log_warning("PoW verification failed: Invalid solution", [
                'nonce' => $nonce,
                'hash' => substr($hash, 0, 16) . '...',
                'expected_prefix' => $requiredPrefix,
                'actual_prefix' => substr($hash, 0, $difficulty)
            ]);
        }

        return $valid;
    }

    /**
     * Get estimated solve time for difficulty level
     *
     * @param int $difficulty Difficulty level
     * @return array ['min_seconds' => float, 'max_seconds' => float, 'avg_seconds' => float]
     */
    public function getEstimatedSolveTime(int $difficulty): array
    {
        // Approximate solve times based on empirical testing
        // Assumes average device (modern smartphone or computer)
        $times = [
            3 => ['min' => 0.1, 'max' => 1, 'avg' => 0.5],
            4 => ['min' => 1, 'max' => 5, 'avg' => 3],
            5 => ['min' => 5, 'max' => 30, 'avg' => 15],
            6 => ['min' => 30, 'max' => 120, 'avg' => 60]
        ];

        return [
            'min_seconds' => $times[$difficulty]['min'],
            'max_seconds' => $times[$difficulty]['max'],
            'avg_seconds' => $times[$difficulty]['avg']
        ];
    }

    /**
     * Calculate optimal difficulty based on security requirements
     *
     * @param string $riskLevel 'low', 'medium', 'high', 'critical'
     * @return int Recommended difficulty
     */
    public function calculateOptimalDifficulty(string $riskLevel): int
    {
        return match($riskLevel) {
            'low' => 3,         // Development/testing
            'medium' => 4,      // Standard production
            'high' => 5,        // E-commerce, financial
            'critical' => 6,    // Critical infrastructure
            default => 4
        };
    }
}
