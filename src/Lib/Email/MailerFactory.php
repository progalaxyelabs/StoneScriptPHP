<?php

declare(strict_types=1);

namespace StoneScriptPHP\Lib\Email;

/**
 * MailerFactory — chooses the email provider per recipient, mirroring
 * the fleet's central auth service's send-time routing:
 *
 *   - recipient domain == TEST_EMAIL_DOMAIN  AND  Mailpit configured
 *         -> MailpitMailer   (plain SMTP, captured by the dev/test mail catcher)
 *   - otherwise
 *         -> MyZeptoMail     (the production ZeptoMail HTTP provider)
 *
 * This is data-driven by the RECIPIENT ADDRESS, not by a dev/prod flag — so a
 * `@<TEST_EMAIL_DOMAIN>` address is captured in Mailpit wherever Mailpit is
 * wired (dev always; prod too if you set MAILPIT_SMTP_* there and ship
 * symfony/mailer), while real customer domains always go to ZeptoMail. It's the
 * same trick that lets auth's OTP emails land in Mailpit during testing.
 *
 * Callers that previously did `new MyZeptoMail()` should use
 * `MailerFactory::forRecipient($to)` instead — most notably the invitation
 * scaffold's email step — so notification/invite mail becomes testable the same
 * way OTP mail already is.
 */
final class MailerFactory
{
    /**
     * The provider to use for a specific recipient address.
     */
    public static function forRecipient(string $to): EmailInterface
    {
        $testDomain = self::testEmailDomain();

        if ($testDomain !== null && self::domainOf($to) === $testDomain) {
            $mailpit = new MailpitMailer();
            if ($mailpit->isConfigured()) {
                return $mailpit;
            }
            // Test-domain address but Mailpit isn't available (not configured,
            // or symfony/mailer absent in a --no-dev build) — fall through to
            // the production provider rather than silently dropping the mail.
        }

        return new MyZeptoMail();
    }

    /**
     * The default (production) provider, for callers with no single recipient
     * to route on (e.g. bulk sends). Kept explicit so call sites read clearly.
     */
    public static function default(): EmailInterface
    {
        return new MyZeptoMail();
    }

    private static function testEmailDomain(): ?string
    {
        $v = self::env('TEST_EMAIL_DOMAIN');
        if ($v === null || trim($v) === '') {
            return null;
        }
        return strtolower(trim($v));
    }

    /**
     * Read an env var the same way StoneScriptPHP\Env::resolveRaw() does
     * (getenv() falling back to $_ENV) — NOT bare getenv() alone.
     *
     * Why this matters: vlucas/phpdotenv's Dotenv::createImmutable()->load()
     * (used by Env::__construct()) does NOT call putenv() by default in v5+ —
     * it only writes $_ENV/$_SERVER. So on any box where these vars arrive
     * exclusively via .env (no OS-level export, no php-fpm pool `env[]`
     * directive), bare getenv() returns false even though the value IS
     * loaded and readable via $_ENV. Before this fix, testEmailDomain() used
     * bare getenv() only, so it silently returned null for EVERY request in
     * that (common) deployment shape — meaning MailerFactory ALWAYS fell
     * through to the production email provider for every recipient, including
     * @<TEST_EMAIL_DOMAIN> test/e2e fixtures with no real mail server. Sending
     * real production traffic to addresses that can never be delivered risks
     * hard bounces against your sending reputation with whatever ESP you use.
     */
    private static function env(string $name): ?string
    {
        $v = getenv($name);
        if ($v === false || $v === '') {
            $v = $_ENV[$name] ?? ($_SERVER[$name] ?? false);
        }
        return ($v === false || $v === '') ? null : (string) $v;
    }

    private static function domainOf(string $email): string
    {
        $at = strrchr($email, '@');
        return $at === false ? '' : strtolower(trim(substr($at, 1)));
    }
}
