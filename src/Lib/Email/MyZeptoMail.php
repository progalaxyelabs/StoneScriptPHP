<?php

namespace StoneScriptPHP\Lib\Email;

use StoneScriptPHP\Env;

/**
 * ZeptoMail Email Service Implementation
 *
 * Implements EmailInterface for ZeptoMail API v1.1
 *
 * Configuration required in .env:
 * - ZEPTOMAIL_BOUNCE_ADDRESS
 * - ZEPTOMAIL_SENDER_EMAIL
 * - ZEPTOMAIL_SENDER_NAME
 * - ZEPTOMAIL_SEND_MAIL_TOKEN
 */
class MyZeptoMail implements EmailInterface
{
    private const API_URL = 'https://api.zeptomail.in/v1.1/email';
    private ?string $lastError = null;

    /**
     * Send a simple email
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $body HTML email body
     * @param string|null $recipientName Recipient name (optional)
     * @return bool Success status
     */
    public function send(string $to, string $subject, string $body, ?string $recipientName = null): bool
    {
        if (!$this->isConfigured()) {
            $this->lastError = 'ZeptoMail not properly configured. Check environment variables.';
            log_error(__METHOD__ . ' ' . $this->lastError);
            return false;
        }

        $postFields = [
            'bounce_address' => Env::$ZEPTOMAIL_BOUNCE_ADDRESS,
            'from' => [
                'address' => Env::$ZEPTOMAIL_SENDER_EMAIL,
                'name' => Env::$ZEPTOMAIL_SENDER_NAME
            ],
            'to' => [
                [
                    'email_address' => [
                        'address' => $to,
                        'name' => $recipientName ?? $to
                    ]
                ]
            ],
            'subject' => $subject,
            'htmlbody' => $body
        ];

        return $this->sendRequest($postFields);
    }

    /**
     * Send email to multiple recipients
     */
    public function sendBulk(array $recipients, string $subject, string $body): bool
    {
        if (!$this->isConfigured()) {
            $this->lastError = 'ZeptoMail not properly configured.';
            log_error(__METHOD__ . ' ' . $this->lastError);
            return false;
        }

        $toList = [];
        foreach ($recipients as $email => $name) {
            if (is_numeric($email)) {
                $toList[] = ['email_address' => ['address' => $name, 'name' => $name]];
            } else {
                $toList[] = ['email_address' => ['address' => $email, 'name' => $name]];
            }
        }

        $postFields = [
            'bounce_address' => Env::$ZEPTOMAIL_BOUNCE_ADDRESS,
            'from' => [
                'address' => Env::$ZEPTOMAIL_SENDER_EMAIL,
                'name' => Env::$ZEPTOMAIL_SENDER_NAME
            ],
            'to' => $toList,
            'subject' => $subject,
            'htmlbody' => $body
        ];

        return $this->sendRequest($postFields);
    }

    /**
     * Send email with template
     */
    public function sendTemplate(
        string $to,
        string $subject,
        string $templateName,
        array $variables = [],
        ?string $recipientName = null
    ): bool {
        $template = $this->loadTemplate($templateName);
        if (!$template) {
            $this->lastError = "Template not found: {$templateName}";
            log_error(__METHOD__ . ' ' . $this->lastError);
            return false;
        }

        $body = $this->replaceVariables($template, $variables);
        return $this->send($to, $subject, $body, $recipientName);
    }

    /**
     * Verify email service configuration
     */
    public function isConfigured(): bool
    {
        return !empty(Env::$ZEPTOMAIL_BOUNCE_ADDRESS)
            && !empty(Env::$ZEPTOMAIL_SENDER_EMAIL)
            && !empty(Env::$ZEPTOMAIL_SENDER_NAME)
            && !empty(Env::$ZEPTOMAIL_SEND_MAIL_TOKEN);
    }

    /**
     * Get last error message
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Send request to ZeptoMail API
     */
    private function sendRequest(array $postFields): bool
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => self::API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($postFields),
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'authorization: ' . Env::$ZEPTOMAIL_SEND_MAIL_TOKEN,
                'cache-control: no-cache',
                'content-type: application/json',
            ],
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        curl_close($curl);

        if ($error) {
            $this->lastError = "cURL error: {$error}";
            log_error(__METHOD__ . ' ' . $this->lastError);
            return false;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->lastError = "ZeptoMail API error (HTTP {$httpCode}): {$response}";
            log_error(__METHOD__ . ' ' . $this->lastError);
            return false;
        }

        log_info(__METHOD__ . ' Email sent successfully', ['response' => $response]);
        return true;
    }

    /**
     * Load email template from file or return as-is
     */
    private function loadTemplate(string $templateName): ?string
    {
        if (file_exists($templateName)) {
            return file_get_contents($templateName);
        }
        return $templateName;
    }

    /**
     * Replace variables in template ({{variable_name}} format)
     */
    private function replaceVariables(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace("{{" . $key . "}}", (string) $value, $template);
        }
        return $template;
    }

    /**
     * Legacy static method for backward compatibility
     * @deprecated Use instance methods instead
     */
    public static function sendLegacy($recipient_email, $recipient_name, $email_subject, $email_body): bool
    {
        $instance = new self();
        return $instance->send($recipient_email, $email_subject, $email_body, $recipient_name);
    }
}
