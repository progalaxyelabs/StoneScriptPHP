<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Lib\Email\MailerFactory;
use StoneScriptPHP\Lib\Email\MailpitMailer;
use StoneScriptPHP\Lib\Email\MyZeptoMail;

/**
 * Unit tests for the test-domain email routing (MailerFactory + MailpitMailer),
 * the framework port of the central auth service's per-recipient provider routing.
 *
 * These drive the env vars directly via putenv() (the classes read getenv() at
 * decision time, exactly so this is controllable), and restore them after.
 *
 * @covers \StoneScriptPHP\Lib\Email\MailerFactory
 * @covers \StoneScriptPHP\Lib\Email\MailpitMailer
 */
final class MailerFactoryTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['TEST_EMAIL_DOMAIN', 'MAILPIT_SMTP_HOST', 'MAILPIT_SMTP_PORT'] as $k) {
            $this->saved[$k] = getenv($k);
            putenv($k); // clear
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === false) {
                putenv($k);
            } else {
                putenv("$k=$v");
            }
        }
    }

    public function test_test_domain_with_mailpit_configured_routes_to_mailpit(): void
    {
        putenv('TEST_EMAIL_DOMAIN=testmail.example.com');
        putenv('MAILPIT_SMTP_HOST=mailpit');
        putenv('MAILPIT_SMTP_PORT=1025');

        // Only meaningful when symfony/mailer is installed (require-dev). In the
        // framework's own test run it is — assert the routing. If it were ever
        // absent, MailpitMailer::isConfigured() would be false and the factory
        // would (correctly) fall back to ZeptoMail; guard so the test states its
        // assumption rather than silently passing.
        $this->assertTrue(
            class_exists(\Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport::class),
            'symfony/mailer (require-dev) must be installed for this assertion'
        );

        $mailer = MailerFactory::forRecipient('someone@testmail.example.com');
        $this->assertInstanceOf(MailpitMailer::class, $mailer);
    }

    public function test_test_domain_is_case_insensitive(): void
    {
        putenv('TEST_EMAIL_DOMAIN=TestMail.Example.com');
        putenv('MAILPIT_SMTP_HOST=mailpit');
        putenv('MAILPIT_SMTP_PORT=1025');

        $mailer = MailerFactory::forRecipient('Someone@TESTMAIL.EXAMPLE.COM');
        $this->assertInstanceOf(MailpitMailer::class, $mailer);
    }

    public function test_non_test_domain_routes_to_zeptomail(): void
    {
        putenv('TEST_EMAIL_DOMAIN=testmail.example.com');
        putenv('MAILPIT_SMTP_HOST=mailpit');
        putenv('MAILPIT_SMTP_PORT=1025');

        $mailer = MailerFactory::forRecipient('real.customer@gmail.com');
        $this->assertInstanceOf(MyZeptoMail::class, $mailer);
    }

    public function test_test_domain_but_mailpit_unconfigured_falls_back_to_zeptomail(): void
    {
        putenv('TEST_EMAIL_DOMAIN=testmail.example.com');
        // MAILPIT_SMTP_* intentionally unset — Mailpit not available.

        $mailer = MailerFactory::forRecipient('someone@testmail.example.com');
        $this->assertInstanceOf(MyZeptoMail::class, $mailer);
    }

    public function test_no_test_domain_configured_always_zeptomail(): void
    {
        // TEST_EMAIL_DOMAIN unset — routing disabled entirely.
        putenv('MAILPIT_SMTP_HOST=mailpit');
        putenv('MAILPIT_SMTP_PORT=1025');

        $mailer = MailerFactory::forRecipient('someone@testmail.example.com');
        $this->assertInstanceOf(MyZeptoMail::class, $mailer);
    }

    public function test_default_is_zeptomail(): void
    {
        $this->assertInstanceOf(MyZeptoMail::class, MailerFactory::default());
    }

    public function test_mailpit_mailer_not_configured_without_env(): void
    {
        // No MAILPIT_SMTP_* → not configured, regardless of symfony/mailer.
        $this->assertFalse((new MailpitMailer())->isConfigured());
    }

    public function test_mailpit_mailer_configured_with_env_and_library(): void
    {
        putenv('MAILPIT_SMTP_HOST=mailpit');
        putenv('MAILPIT_SMTP_PORT=1025');
        $expected = class_exists(\Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport::class);
        $this->assertSame($expected, (new MailpitMailer())->isConfigured());
    }
}
