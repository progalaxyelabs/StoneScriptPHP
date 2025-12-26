<?php

namespace StoneScriptPHP\Security;

/**
 * hCaptcha Verification
 *
 * Privacy-focused CAPTCHA alternative to Google reCAPTCHA.
 * Free tier: 1M requests/month
 *
 * Setup:
 * 1. Sign up at https://www.hcaptcha.com/
 * 2. Get site key and secret key
 * 3. Add to .env:
 *    HCAPTCHA_SITE_KEY=your-site-key
 *    HCAPTCHA_SECRET_KEY=your-secret-key
 *
 * Features:
 * - Privacy-focused (GDPR compliant)
 * - Pays websites (Proof of Human)
 * - Open source friendly
 * - Better accessibility
 */
class HCaptchaVerifier
{
    private const VERIFY_URL = 'https://hcaptcha.com/siteverify';

    private ?string $siteKey;
    private ?string $secretKey;
    private ?string $lastError = null;

    public function __construct(?string $secretKey = null, ?string $siteKey = null)
    {
        $this->secretKey = $secretKey ?? getenv('HCAPTCHA_SECRET_KEY');
        $this->siteKey = $siteKey ?? getenv('HCAPTCHA_SITE_KEY');
    }

    /**
     * Check if hCaptcha is configured
     */
    public function isEnabled(): bool
    {
        return !empty($this->secretKey) && !empty($this->siteKey);
    }

    /**
     * Get site key for frontend
     */
    public function getSiteKey(): ?string
    {
        return $this->siteKey;
    }

    /**
     * Verify hCaptcha response token
     *
     * @param string $token Response token from client
     * @param string|null $remoteIp Client IP address (optional but recommended)
     * @return bool True if verification passed
     */
    public function verify(string $token, ?string $remoteIp = null): bool
    {
        if (!$this->isEnabled()) {
            log_warning("hCaptcha verification skipped: Not configured");
            return true; // If not configured, allow (fail open)
        }

        if (empty($token)) {
            $this->lastError = 'hCaptcha token is required';
            log_warning("hCaptcha verification failed: Empty token");
            return false;
        }

        try {
            $response = $this->sendVerificationRequest($token, $remoteIp);

            if ($response['success']) {
                log_debug("hCaptcha verification successful", [
                    'hostname' => $response['hostname'] ?? null,
                    'challenge_ts' => $response['challenge_ts'] ?? null
                ]);
                return true;
            }

            // Verification failed
            $errorCodes = $response['error-codes'] ?? [];
            $this->lastError = $this->getErrorMessage($errorCodes);

            log_warning("hCaptcha verification failed", [
                'error_codes' => $errorCodes,
                'error_message' => $this->lastError,
                'ip' => $remoteIp ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
            ]);

            return false;

        } catch (\Exception $e) {
            $this->lastError = 'hCaptcha verification request failed: ' . $e->getMessage();
            log_error("hCaptcha API error: {$e->getMessage()}");

            // Fail open on API errors (don't block users if hCaptcha is down)
            return true;
        }
    }

    /**
     * Get last error message
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Send verification request to hCaptcha API
     */
    private function sendVerificationRequest(string $token, ?string $remoteIp): array
    {
        $postData = [
            'secret' => $this->secretKey,
            'response' => $token
        ];

        if ($remoteIp) {
            $postData['remoteip'] = $remoteIp;
        }

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query($postData),
                'timeout' => 10
            ]
        ];

        $context = stream_context_create($options);

        // Use custom error handler to get detailed error message
        set_error_handler(function ($errno, $errstr) {
            throw new \ErrorException($errstr, 0, $errno);
        }, E_WARNING);

        try {
            $result = file_get_contents(self::VERIFY_URL, false, $context);
            restore_error_handler();

            if ($result === false) {
                throw new \RuntimeException('Failed to connect to hCaptcha API');
            }
        } catch (\ErrorException $e) {
            restore_error_handler();
            throw new \RuntimeException('Failed to connect to hCaptcha API: ' . $e->getMessage(), 0, $e);
        }

        $response = json_decode($result, true);

        if (!is_array($response)) {
            throw new \RuntimeException('Invalid response from hCaptcha API');
        }

        return $response;
    }

    /**
     * Get human-readable error message from error codes
     */
    private function getErrorMessage(array $errorCodes): string
    {
        if (empty($errorCodes)) {
            return 'Unknown error';
        }

        $messages = [
            'missing-input-secret' => 'Secret key is missing',
            'invalid-input-secret' => 'Secret key is invalid',
            'missing-input-response' => 'Response token is missing',
            'invalid-input-response' => 'Response token is invalid or expired',
            'bad-request' => 'Bad request',
            'invalid-or-already-seen-response' => 'Response token has already been used',
            'not-using-dummy-passcode' => 'Not using dummy passcode in test mode',
            'sitekey-secret-mismatch' => 'Site key and secret key mismatch'
        ];

        $firstError = $errorCodes[0];
        return $messages[$firstError] ?? "hCaptcha error: $firstError";
    }

    /**
     * Get configuration for frontend
     */
    public function getFrontendConfig(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'site_key' => $this->getSiteKey()
        ];
    }
}
