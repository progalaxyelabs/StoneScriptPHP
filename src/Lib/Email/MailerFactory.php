<?php

declare(strict_types=1);

namespace StoneScriptPHP\Lib\Email;

/**
 * MailerFactory — chooses the email provider per recipient, mirroring
 * progalaxyelabs-auth's send-time routing (docker/auth/src/handlers/otp.rs):
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
        $v = getenv('TEST_EMAIL_DOMAIN');
        if ($v === false || trim($v) === '') {
            return null;
        }
        return strtolower(trim($v));
    }

    private static function domainOf(string $email): string
    {
        $at = strrchr($email, '@');
        return $at === false ? '' : strtolower(trim(substr($at, 1)));
    }
}
