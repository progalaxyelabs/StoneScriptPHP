<?php

namespace Framework\Lib\Email;

/**
 * Email Service Interface
 *
 * Common interface for all email service providers (ZeptoMail, SendGrid, AWS SES, etc.)
 */
interface EmailInterface
{
    /**
     * Send a simple email
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $body HTML email body
     * @param string|null $recipientName Recipient name (optional)
     * @return bool Success status
     */
    public function send(string $to, string $subject, string $body, ?string $recipientName = null): bool;

    /**
     * Send email to multiple recipients
     *
     * @param array $recipients Array of ['email' => 'name'] or just email addresses
     * @param string $subject Email subject
     * @param string $body HTML email body
     * @return bool Success status
     */
    public function sendBulk(array $recipients, string $subject, string $body): bool;

    /**
     * Send email with template
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $templateName Template name/ID
     * @param array $variables Template variables
     * @param string|null $recipientName Recipient name (optional)
     * @return bool Success status
     */
    public function sendTemplate(
        string $to,
        string $subject,
        string $templateName,
        array $variables = [],
        ?string $recipientName = null
    ): bool;

    /**
     * Verify email service configuration
     *
     * @return bool True if properly configured
     */
    public function isConfigured(): bool;

    /**
     * Get last error message
     *
     * @return string|null Error message or null
     */
    public function getLastError(): ?string;
}
