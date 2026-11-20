<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Tenancy\Platform\PlatformRotateCredentialCommand;
use StoneScriptPHP\Tenancy\Platform\SecretsFile;
use StoneScriptPHP\Tenancy\Provisioning\AuthV2Client;

/**
 * @covers \StoneScriptPHP\Tenancy\Platform\PlatformRotateCredentialCommand
 */
final class PlatformRotateCredentialCommandTest extends TestCase
{
    private const PLATFORM = '12121212-1212-4212-8212-121212121212';
    private const OPERATOR = 'operator-token-do-not-print';
    private const NEXT_SECRET = 'next-secret-do-not-print';

    private string $dir;
    private string $stdout = '';
    private string $stderr = '';
    /** @var list<array{string,string,list<string>,?string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ssp-rotate-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/.stone', 0700, true);
        mkdir($this->dir . '/project', 0700, true);
        file_put_contents($this->dir . '/.stone/operator.env', 'STONE_OPERATOR_TOKEN=' . self::OPERATOR . "\n");
        chmod($this->dir . '/.stone/operator.env', 0600);
        file_put_contents($this->dir . '/project/.env', "PLATFORM_UUID=" . self::PLATFORM . "\nEXTERNAL_AUTH_CLIENT_SECRET=live-14x\nAUTH_V2_CLIENT_ID=current-id\nAUTH_V2_CLIENT_SECRET=current-secret\n");
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            ($f->isLink() || $f->isFile()) ? unlink($f->getPathname()) : rmdir($f->getPathname());
        }
        rmdir($this->dir);
    }

    private function command(int $status, array $body): PlatformRotateCredentialCommand
    {
        return new PlatformRotateCredentialCommand(
            function (string $t): void { $this->stdout .= $t; },
            function (string $t): void { $this->stderr .= $t; },
            fn (string $url): AuthV2Client => new AuthV2Client($url, null, null, function (string $m, string $u, array $h, ?string $b) use ($status, $body): array {
                $this->requests[] = [$m, $u, $h, $b];
                return [$status, json_encode($body)];
            }),
        );
    }

    private function go(PlatformRotateCredentialCommand $c, array $args = []): int
    {
        return $c->run($args, [], $this->dir, $this->dir . '/project', 'https://auth.example.test', new \DateTimeImmutable('2026-11-21 10:00:00', new \DateTimeZone('UTC')));
    }

    public function test_rotation_writes_only_the_next_variables_and_prints_no_secret(): void
    {
        $status = $this->go($this->command(201, ['platform_id' => self::PLATFORM, 'client_id' => 'next-id', 'status' => 'next', 'credential_issued' => true, 'idempotent_replay' => false, 'client_secret' => self::NEXT_SECRET]));

        $this->assertSame(0, $status, $this->stderr);
        [$method, $url, $headers, $body] = $this->requests[0];
        $this->assertSame('POST', $method);
        $this->assertSame('https://auth.example.test/api/internal/v2/operator/platforms/' . self::PLATFORM . '/credentials/rotate', $url);
        $this->assertContains('X-Operator-Token: ' . self::OPERATOR, $headers);
        $this->assertSame(['idempotency_key' => 'rotate:' . self::PLATFORM . ':20261121'], json_decode((string) $body, true));

        $file = new SecretsFile($this->dir . '/project/.env');
        $this->assertSame('next-id', $file->get('AUTH_V2_CLIENT_ID_NEXT'));
        $this->assertSame(self::NEXT_SECRET, $file->get('AUTH_V2_CLIENT_SECRET_NEXT'));
        $this->assertSame('current-id', $file->get('AUTH_V2_CLIENT_ID'), 'the credential in use is not touched');
        $this->assertSame('current-secret', $file->get('AUTH_V2_CLIENT_SECRET'));
        $this->assertSame('live-14x', $file->get('EXTERNAL_AUTH_CLIENT_SECRET'), 'the 14.x secret is not touched');
        foreach ([self::OPERATOR, self::NEXT_SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, $this->stdout . $this->stderr);
        }
    }

    public function test_a_replay_has_no_secret_and_the_command_says_so_without_writing(): void
    {
        $status = $this->go($this->command(200, ['platform_id' => self::PLATFORM, 'client_id' => 'next-id', 'status' => 'next', 'credential_issued' => false, 'idempotent_replay' => true]));

        $this->assertSame(1, $status);
        $this->assertStringContainsString('shown once', $this->stderr);
        $this->assertNull((new SecretsFile($this->dir . '/project/.env'))->get('AUTH_V2_CLIENT_ID_NEXT'));
    }

    public function test_auth_errors_and_a_missing_platform_id_stop_early(): void
    {
        $this->assertSame(1, $this->go($this->command(409, ['error' => 'next_credential_exists'])));
        $this->assertStringContainsString('next_credential_exists', $this->stderr);

        $this->stderr = '';
        file_put_contents($this->dir . '/project/.env', "AUTH_V2_CLIENT_ID=x\n");
        $this->requests = [];
        $this->assertSame(1, $this->go($this->command(201, [])));
        $this->assertStringContainsString('platform-id', $this->stderr);
        $this->assertSame([], $this->requests);
    }

    public function test_http_is_refused_for_the_operator_call(): void
    {
        $status = $this->command(201, [])->run([], [], $this->dir, $this->dir . '/project', 'http://auth.example.test');

        $this->assertSame(1, $status);
        $this->assertStringContainsString('https', $this->stderr);
        $this->assertSame([], $this->requests);
    }
}
