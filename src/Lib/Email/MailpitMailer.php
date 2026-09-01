<?php

declare(strict_types=1);

namespace StoneScriptPHP\Lib\Email;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * MailpitMailer — an EmailInterface provider that delivers over plain SMTP to
 * Mailpit (https://mailpit.axllent.org), the fleet's dev/test mail catcher.
 * Mirrors the fleet's central auth service's own Mailpit provider: no auth, no TLS,
 * internal network only.
 *
 * WHY THIS EXISTS: the production provider (MyZeptoMail) talks to the ZeptoMail
 * HTTP API, which needs real credentials that don't exist in dev — so in dev
 * every email was silently skipped (isConfigured() false). Auth solved this
 * with per-recipient test-domain routing: mail to a TEST_EMAIL_DOMAIN address
 * goes to Mailpit, real domains go to ZeptoMail. This class is the Mailpit half
 * of that; MailerFactory is the router. See MailerFactory for the routing rule.
 *
 * DEPENDENCY NOTE: this uses symfony/mailer, which the framework declares as a
 * require-DEV dependency (for its own tests). Two consequences, both handled by
 * the class_exists(EsmtpTransport) guard in isConfigured() — which returns false
 * (so MailerFactory falls back to ZeptoMail) whenever the library is absent:
 *   1. A production build (`composer install --no-dev`) won't have it.
 *   2. require-dev is NOT transitive — a platform that depends on this framework
 *      does NOT inherit symfony/mailer. A consuming platform that wants the
 *      Mailpit dev path must add `symfony/mailer` to ITS OWN require-dev (and,
 *      to route test addresses to Mailpit in production too like auth does,
 *      promote it to `require` and set MAILPIT_SMTP_* in the prod environment).
 */
class MailpitMailer implements EmailInterface
{
    private ?string $lastError = null;

    public function send(string $to, string $subject, string $body, ?string $recipientName = null): bool
    {
        if (!$this->isConfigured()) {
            $this->lastError = 'Mailpit SMTP not configured (MAILPIT_SMTP_HOST/PORT unset) '
                . 'or symfony/mailer not installed (require-dev).';
            log_warning(__METHOD__ . ' ' . $this->lastError);
            return false;
        }

        try {
            // Plain SMTP — no TLS, no auth (internal dev/test network). The
            // third EsmtpTransport arg (tls) left null keeps it unencrypted,
            // matching Mailpit's listener and auth's builder_dangerous().
            $transport = new EsmtpTransport($this->host(), (int) $this->port());
            $mailer = new Mailer($transport);

            $email = (new Email())
                ->from($this->fromAddress())
                ->to($to)
                ->subject($subject)
                ->html($body);

            $mailer->send($email);
            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            log_error(__METHOD__ . ' Mailpit send failed: ' . $e->getMessage());
            return false;
        }
    }

    public function sendBulk(array $recipients, string $subject, string $body): bool
    {
        $ok = true;
        foreach ($recipients as $key => $value) {
            // Accept both ['email' => 'name'] and a flat list of addresses.
            $address = is_string($key) && str_contains($key, '@') ? $key : (string) $value;
            if ($address === '' || !str_contains($address, '@')) {
                continue;
            }
            $ok = $this->send($address, $subject, $body) && $ok;
        }
        return $ok;
    }

    public function sendTemplate(
        string $to,
        string $subject,
        string $templateName,
        array $variables = [],
        ?string $recipientName = null
    ): bool {
        // Mailpit has no server-side templates; render a trivial body so a
        // template-based caller still lands something visible in the catcher.
        $rows = '';
        foreach ($variables as $k => $v) {
            $rows .= '<tr><td><strong>' . htmlspecialchars((string) $k, ENT_QUOTES) . '</strong></td>'
                . '<td>' . htmlspecialchars(is_scalar($v) ? (string) $v : json_encode($v), ENT_QUOTES) . '</td></tr>';
        }
        $body = '<p>[template: ' . htmlspecialchars($templateName, ENT_QUOTES) . ']</p>'
            . ($rows !== '' ? "<table>{$rows}</table>" : '');
        return $this->send($to, $subject, $body, $recipientName);
    }

    public function isConfigured(): bool
    {
        return class_exists(EsmtpTransport::class)
            && !empty($this->host())
            && !empty($this->port());
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    private function host(): ?string
    {
        return self::env('MAILPIT_SMTP_HOST');
    }

    private function port(): ?string
    {
        return self::env('MAILPIT_SMTP_PORT');
    }

    /**
     * Sender address for Mailpit mail. Mailpit accepts any from address, so
     * reuse the configured production sender if present, else a neutral default
     * (only ever seen in the dev/test catcher).
     */
    private function fromAddress(): string
    {
        return self::env('ZEPTOMAIL_SENDER_EMAIL') ?? self::env('MAIL_FROM_ADDRESS') ?? 'no-reply@localhost';
    }

    /**
     * Read an env var the same way StoneScriptPHP\Env::resolveRaw() does
     * (getenv() falling back to $_ENV/$_SERVER) — see MailerFactory::env()
     * for the full explanation of why bare getenv() alone is unreliable here
     * (phpdotenv v5+ does not call putenv() by default).
     */
    private static function env(string $name): ?string
    {
        $v = getenv($name);
        if ($v === false || $v === '') {
            $v = $_ENV[$name] ?? ($_SERVER[$name] ?? false);
        }
        return ($v === false || $v === '') ? null : (string) $v;
    }
}
