<?php

namespace StoneScriptPHP\Lib\Email;

/**
 * Email Validator with DNS/MX Record Verification
 *
 * Validates email addresses beyond regex to prevent bounces:
 * 1. Format validation (RFC 5322)
 * 2. DNS MX record verification
 * 3. Disposable email detection (optional)
 * 4. Role-based email detection (optional)
 *
 * Usage:
 *   $validator = new EmailValidator();
 *   if ($validator->validate('user@example.com')) {
 *       // Email is valid
 *   } else {
 *       echo $validator->getError();
 *   }
 */
class EmailValidator
{
    private ?string $lastError = null;

    /**
     * Common disposable email domains
     */
    private const DISPOSABLE_DOMAINS = [
        'tempmail.com', 'throwaway.email', '10minutemail.com', 'guerrillamail.com',
        'mailinator.com', 'maildrop.cc', 'temp-mail.org', 'getnada.com',
        'yopmail.com', 'fakeinbox.com', 'trashmail.com', 'mohmal.com'
    ];

    /**
     * Common role-based email prefixes
     */
    private const ROLE_PREFIXES = [
        'admin', 'info', 'support', 'sales', 'contact', 'help',
        'postmaster', 'webmaster', 'noreply', 'no-reply'
    ];

    /**
     * Validate email address with comprehensive checks
     *
     * @param string $email Email address to validate
     * @param bool $checkMx Verify DNS MX records (default: true)
     * @param bool $checkDisposable Check for disposable email domains (default: false)
     * @param bool $checkRole Check for role-based emails (default: false)
     * @return bool True if valid
     */
    public function validate(
        string $email,
        bool $checkMx = true,
        bool $checkDisposable = false,
        bool $checkRole = false
    ): bool {
        $this->lastError = null;

        // 1. Format validation using PHP's built-in filter
        if (!$this->validateFormat($email)) {
            $this->lastError = 'Invalid email format';
            return false;
        }

        // 2. Check for disposable email domains
        if ($checkDisposable && $this->isDisposable($email)) {
            $this->lastError = 'Disposable email addresses are not allowed';
            return false;
        }

        // 3. Check for role-based emails
        if ($checkRole && $this->isRoleBased($email)) {
            $this->lastError = 'Role-based email addresses are not allowed';
            return false;
        }

        // 4. Verify DNS MX records
        if ($checkMx && !$this->validateMxRecord($email)) {
            $this->lastError = 'Email domain does not have valid MX records';
            return false;
        }

        return true;
    }

    /**
     * Validate email format using PHP's filter_var
     *
     * Uses FILTER_VALIDATE_EMAIL which implements RFC 5322
     *
     * @param string $email
     * @return bool
     */
    public function validateFormat(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Verify domain has valid MX records
     *
     * This checks if the domain can actually receive emails
     *
     * @param string $email
     * @return bool
     */
    public function validateMxRecord(string $email): bool
    {
        $domain = $this->extractDomain($email);

        if (!$domain) {
            return false;
        }

        // Check for MX records
        $mxHosts = [];
        if (getmxrr($domain, $mxHosts)) {
            return count($mxHosts) > 0;
        }

        // Fallback: Check for A record (some domains use A records for mail)
        return checkdnsrr($domain, 'A');
    }

    /**
     * Check if email is from a disposable email service
     *
     * @param string $email
     * @return bool
     */
    public function isDisposable(string $email): bool
    {
        $domain = strtolower($this->extractDomain($email));

        return in_array($domain, self::DISPOSABLE_DOMAINS, true);
    }

    /**
     * Check if email is role-based (admin@, info@, etc.)
     *
     * @param string $email
     * @return bool
     */
    public function isRoleBased(string $email): bool
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return false;
        }

        $localPart = strtolower($parts[0]);

        return in_array($localPart, self::ROLE_PREFIXES, true);
    }

    /**
     * Extract domain from email address
     *
     * @param string $email
     * @return string|null
     */
    private function extractDomain(string $email): ?string
    {
        $parts = explode('@', $email);
        return count($parts) === 2 ? strtolower($parts[1]) : null;
    }

    /**
     * Get last validation error
     *
     * @return string|null
     */
    public function getError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Quick validation (format + MX only)
     *
     * @param string $email
     * @return bool
     */
    public static function isValid(string $email): bool
    {
        $validator = new self();
        return $validator->validate($email, true, false, false);
    }

    /**
     * Strict validation (all checks enabled)
     *
     * @param string $email
     * @return bool
     */
    public static function isValidStrict(string $email): bool
    {
        $validator = new self();
        return $validator->validate($email, true, true, true);
    }
}
