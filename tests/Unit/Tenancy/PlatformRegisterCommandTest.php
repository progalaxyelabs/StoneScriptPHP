<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Tenancy\Platform\PlatformRegisterCommand;
use StoneScriptPHP\Tenancy\Platform\PlatformRowStore;
use StoneScriptPHP\Tenancy\Platform\SecretsFile;
use StoneScriptPHP\Tenancy\Provisioning\AuthTenancyClient;

/**
 * `php stone platform:register`: operator token sources, idempotent retry, write order, and that no
 * secret is ever printed.
 *
 * @covers \StoneScriptPHP\Tenancy\Platform\PlatformRegisterCommand
 * @covers \StoneScriptPHP\Tenancy\Platform\SecretsFile
 */
final class PlatformRegisterCommandTest extends TestCase
{
    private const PLATFORM_ID = '12121212-1212-4212-8212-121212121212';
    private const OPERATOR_TOKEN = 'operator-token-do-not-print';
    private const CLIENT_SECRET = 'client-secret-do-not-print';

    private string $dir;
    /** @var list<array{string,string,list<string>,?string}> */
    private array $requests = [];
    private string $stdout = '';
    private string $stderr = '';
    /** @var list<array<string,string>> */
    private array $rowWrites = [];
    private ?\Throwable $rowFailure = null;
    /** @var list<string> */
    private array $order = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ssp-platform-register-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/.stone', 0700, true);
        mkdir($this->dir . '/project', 0700, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            ($f->isLink() || $f->isFile()) ? unlink($f->getPathname()) : rmdir($f->getPathname());
        }
        rmdir($this->dir);
    }

    /** @param array{0:int,1:string}|null $response */
    private function command(?array $response = null): PlatformRegisterCommand
    {
        $response ??= [201, json_encode(['platform_id' => self::PLATFORM_ID, 'client_id' => 'client-7', 'client_secret' => self::CLIENT_SECRET])];
        $rows = new class ($this) implements PlatformRowStore {
            public function __construct(private PlatformRegisterCommandTest $test)
            {
            }

            public function register(string $platformId, string $name, string $slug, string $authMode): void
            {
                $this->test->recordRow($platformId, $name, $slug, $authMode);
            }
        };
        return new PlatformRegisterCommand(
            function (string $t): void { $this->stdout .= $t; },
            function (string $t): void { $this->stderr .= $t; },
            fn (string $url): AuthTenancyClient => new AuthTenancyClient($url, null, null, function (string $m, string $u, array $h, ?string $b) use ($response): array {
                $this->requests[] = [$m, $u, $h, $b];
                $this->order[] = 'auth';
                return $response;
            }),
            $rows,
        );
    }

    public function recordRow(string $platformId, string $name, string $slug, string $authMode): void
    {
        $this->order[] = 'row';
        if ($this->rowFailure !== null) {
            throw $this->rowFailure;
        }
        $this->rowWrites[] = compact('platformId', 'name', 'slug', 'authMode');
    }

    private function operatorFile(string $contents = 'STONE_OPERATOR_TOKEN=' . self::OPERATOR_TOKEN . "\n", int $mode = 0600): string
    {
        $path = $this->dir . '/.stone/operator.env';
        file_put_contents($path, $contents);
        chmod($path, $mode);
        return $path;
    }

    /** @param list<string> $args @param array<string,string> $env */
    private function register(PlatformRegisterCommand $command, array $args = ['--name', 'Acme Shop', '--slug', 'acme'], array $env = []): int
    {
        return $command->run($args, $env, $this->dir, $this->dir . '/project', 'https://auth.example.test');
    }

    private function assertNoSecretsPrinted(): void
    {
        foreach ([self::OPERATOR_TOKEN, self::CLIENT_SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, $this->stdout);
            $this->assertStringNotContainsString($secret, $this->stderr);
        }
    }

    public function test_registers_prints_only_platform_id_and_slug_and_writes_the_credential_to_the_secrets_file(): void
    {
        $this->operatorFile();

        $status = $this->register($this->command());

        $this->assertSame(0, $status);
        $this->assertSame("platform_id: " . self::PLATFORM_ID . "\nslug: acme\n", $this->stdout);
        $this->assertSame('', $this->stderr);
        $this->assertNoSecretsPrinted();

        $secrets = new SecretsFile($this->dir . '/project/.env');
        $this->assertSame('client-7', $secrets->get('PLATFORM_CLIENT_ID'));
        $this->assertSame(self::CLIENT_SECRET, $secrets->get('PLATFORM_CLIENT_SECRET'));
        $this->assertNull($secrets->get('EXTERNAL_AUTH_CLIENT_SECRET'), 'the 14.x variable is never created by platform:register');
        $this->assertSame(self::PLATFORM_ID, $secrets->get('PLATFORM_UUID'));
        $this->assertNull($secrets->get('PLATFORM_ID'), 'PLATFORM_ID is the legacy slug variable and is never overwritten with the uuid');
        $this->assertSame(0600, fileperms($this->dir . '/project/.env') & 0777, 'a created secrets file is private');
        $this->assertSame([['platformId' => self::PLATFORM_ID, 'name' => 'Acme Shop', 'slug' => 'acme', 'authMode' => 'external']], $this->rowWrites);
    }

    public function test_request_carries_the_operator_token_only_as_a_bearer_header_and_a_stable_idempotency_key(): void
    {
        $this->operatorFile();

        $this->register($this->command());

        [$method, $url, $headers, $body] = $this->requests[0];
        $this->assertSame('POST', $method);
        $this->assertSame('https://auth.example.test/api/internal/operator/platforms', $url);
        $this->assertContains('X-Operator-Token: ' . self::OPERATOR_TOKEN, $headers);
        $this->assertSame(['name' => 'Acme Shop', 'slug' => 'acme', 'idempotency_key' => 'register:acme'], json_decode((string) $body, true));
        $this->assertStringNotContainsString(self::OPERATOR_TOKEN, (string) $body);
    }

    public function test_the_credential_is_saved_before_the_platform_row_is_written(): void
    {
        $this->operatorFile();

        $this->register($this->command());

        $this->assertSame(['auth', 'row'], $this->order);
        $this->assertNotNull((new SecretsFile($this->dir . '/project/.env'))->get('PLATFORM_CLIENT_SECRET'), 'the secret exists by the time the row is written');
    }

    public function test_a_row_failure_keeps_the_saved_credential_and_a_retry_completes_without_a_new_secret(): void
    {
        $this->operatorFile();
        $this->rowFailure = new \RuntimeException('database down ' . self::CLIENT_SECRET);

        $first = $this->register($this->command());

        $this->assertSame(1, $first);
        $this->assertStringContainsString('credential was saved', $this->stderr);
        $this->assertNoSecretsPrinted();
        $this->assertSame(self::CLIENT_SECRET, (new SecretsFile($this->dir . '/project/.env'))->get('PLATFORM_CLIENT_SECRET'));

        // The retry: auth returns the same platform and, as promised, no second secret.
        $this->rowFailure = null;
        $this->stdout = $this->stderr = '';
        $retry = $this->register($this->command([200, json_encode(['platform_id' => self::PLATFORM_ID, 'client_id' => 'client-7'])]));

        $this->assertSame(0, $retry);
        $this->assertSame(self::CLIENT_SECRET, (new SecretsFile($this->dir . '/project/.env'))->get('PLATFORM_CLIENT_SECRET'), 'the stored secret is untouched');
        $this->assertCount(1, $this->rowWrites);
    }

    public function test_a_retry_with_no_secret_from_auth_and_none_stored_stops_and_says_to_rotate(): void
    {
        $this->operatorFile();

        $status = $this->register($this->command([200, json_encode(['platform_id' => self::PLATFORM_ID, 'client_id' => 'client-7'])]));

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Rotate', $this->stderr);
        $this->assertSame([], $this->rowWrites, 'nothing is recorded when the credential cannot be stored');
    }

    public function test_existing_secrets_file_keeps_its_other_lines_and_its_mode(): void
    {
        $this->operatorFile();
        $env = $this->dir . '/project/.env';
        file_put_contents($env, "# app\nAPP_NAME=shop\nEXTERNAL_AUTH_CLIENT_SECRET=live-14x-secret\nPLATFORM_CLIENT_SECRET=old\nDB_X=1\n");
        chmod($env, 0640);

        $this->register($this->command());

        $content = (string) file_get_contents($env);
        $this->assertStringContainsString("# app\nAPP_NAME=shop\nEXTERNAL_AUTH_CLIENT_SECRET=live-14x-secret\nPLATFORM_CLIENT_SECRET=" . self::CLIENT_SECRET . "\nDB_X=1\n", $content);
        $this->assertSame(1, substr_count($content, 'PLATFORM_CLIENT_SECRET='));
        $this->assertSame(0640, fileperms($env) & 0777);
    }

    public function test_the_live_14x_secret_is_left_byte_identical(): void
    {
        $this->operatorFile();
        $env = $this->dir . '/project/.env';
        $original = "EXTERNAL_AUTH_CLIENT_ID=legacy-client\nEXTERNAL_AUTH_CLIENT_SECRET=live-14x-secret\n";
        file_put_contents($env, $original);

        $this->assertSame(0, $this->register($this->command()));

        $after = (string) file_get_contents($env);
        $this->assertStringStartsWith($original, $after, 'the 14.x lines are untouched, byte for byte, and nothing was inserted before them');
        $this->assertSame('live-14x-secret', (new SecretsFile($env))->get('EXTERNAL_AUTH_CLIENT_SECRET'));
        $this->assertSame('legacy-client', (new SecretsFile($env))->get('EXTERNAL_AUTH_CLIENT_ID'));
    }

    public function test_operator_calls_need_https_except_for_localhost(): void
    {
        $this->operatorFile();

        foreach (['http://auth.example.test', 'http://localhost.evil.test'] as $url) {
            $this->stderr = '';
            $status = $this->command()->run(['--name', 'Acme', '--slug', 'acme'], [], $this->dir, $this->dir . '/project', $url);
            $this->assertSame(1, $status, $url);
            $this->assertStringContainsString('https', $this->stderr);
        }
        $this->assertSame([], $this->requests, 'the operator token is never sent over plain http');

        foreach (['http://localhost:3139', 'http://127.0.0.1:3139', 'https://auth.example.test'] as $url) {
            $status = $this->command()->run(['--name', 'Acme', '--slug', 'acme'], [], $this->dir, $this->dir . '/project', $url);
            $this->assertSame(0, $status, $url);
        }
    }

    public function test_operator_token_from_the_command_environment_is_accepted_and_beats_the_default_file(): void
    {
        $this->operatorFile('STONE_OPERATOR_TOKEN=from-file' . "\n");

        $this->register($this->command(), ['--name', 'Acme', '--slug', 'acme'], [PlatformRegisterCommand::TOKEN_VAR => 'from-env']);

        $this->assertContains('X-Operator-Token: from-env', $this->requests[0][2]);
    }

    public function test_explicit_operator_env_file_is_used(): void
    {
        $custom = $this->dir . '/custom.env';
        file_put_contents($custom, "export STONE_OPERATOR_TOKEN=\"custom-token\"\n");
        chmod($custom, 0600);

        $status = $this->register($this->command(), ['--name', 'Acme', '--slug', 'acme', '--operator-env=' . $custom]);

        $this->assertSame(0, $status);
        $this->assertContains('X-Operator-Token: custom-token', $this->requests[0][2]);
    }

    public function test_the_operator_token_is_never_read_from_the_application_env_file(): void
    {
        // The token sits in the app .env: it must NOT be found, and pointing --operator-env at it is refused.
        file_put_contents($this->dir . '/project/.env', 'STONE_OPERATOR_TOKEN=' . self::OPERATOR_TOKEN . "\n");
        chmod($this->dir . '/project/.env', 0600);

        $status = $this->register($this->command());
        $this->assertSame(1, $status);
        $this->assertStringContainsString('No operator token', $this->stderr);
        $this->assertSame([], $this->requests, 'nothing was sent');

        $this->stderr = '';
        $status = $this->register($this->command(), ['--name', 'Acme', '--slug', 'acme', '--operator-env=' . $this->dir . '/project/.env']);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('must not live in the application', $this->stderr);
        $this->assertSame([], $this->requests);
        $this->assertNoSecretsPrinted();
    }

    public function test_an_operator_file_readable_by_others_is_refused(): void
    {
        $this->operatorFile(mode: 0644);

        $status = $this->register($this->command());

        $this->assertSame(1, $status);
        $this->assertStringContainsString('chmod 600', $this->stderr);
        $this->assertSame([], $this->requests);
        $this->assertNoSecretsPrinted();
    }

    public function test_missing_token_everywhere_is_an_error_that_names_where_to_put_it(): void
    {
        $status = $this->register($this->command());

        $this->assertSame(1, $status);
        $this->assertStringContainsString('operator.env', $this->stderr);
        $this->assertSame([], $this->requests);
    }

    public function test_auth_errors_do_not_leak_secrets_and_record_nothing(): void
    {
        $this->operatorFile();

        $status = $this->register($this->command([403, json_encode(['error' => 'forbidden', 'echo' => self::OPERATOR_TOKEN])]));

        $this->assertSame(1, $status);
        $this->assertStringContainsString('HTTP 403', $this->stderr);
        $this->assertNoSecretsPrinted();
        $this->assertSame([], $this->rowWrites);
        $this->assertFileDoesNotExist($this->dir . '/project/.env');
    }

    public function test_bad_arguments_are_refused_locally_before_any_request(): void
    {
        $this->operatorFile();
        $bad = [
            ['--name', 'Acme'],
            ['--slug', 'acme'],
            ['--name', 'Acme', '--slug', 'Acme'],
            ['--name', 'Acme', '--slug', 'acme-shop'],
            ['--name', 'Acme', '--slug', 'acme__shop'],
            ['--name', 'Acme', '--slug', str_repeat('a', 20)],
            ['--name', 'Acme', '--slug', 'acme', '--bogus', 'x'],
            ['--name'],
        ];
        foreach ($bad as $args) {
            $this->stderr = '';
            $this->assertSame(1, $this->register($this->command(), $args), implode(' ', $args));
            $this->assertNotSame('', $this->stderr);
        }
        $this->assertSame([], $this->requests);
    }

    public function test_a_slug_of_exactly_19_characters_is_accepted_and_20_is_refused(): void
    {
        $this->operatorFile();

        $this->assertSame(0, $this->register($this->command(), ['--name', 'Acme', '--slug', str_repeat('a', 19)]));
        $this->stderr = '';
        $this->assertSame(1, $this->register($this->command(), ['--name', 'Acme', '--slug', str_repeat('a', 20)]));
        $this->assertStringContainsString('at most 19', $this->stderr);
    }

    public function test_help_prints_usage_and_exits_zero_without_a_request(): void
    {
        $this->assertSame(0, $this->register($this->command(), ['--help']));
        $this->assertStringContainsString('--slug', $this->stdout);
        $this->assertSame([], $this->requests);
    }

    public function test_secrets_file_refuses_values_that_could_inject_another_variable(): void
    {
        $file = new SecretsFile($this->dir . '/x.env');

        foreach (["a\nB=1", 'a b', 'a"b', 'a#b', '', 'a$b'] as $unsafe) {
            try {
                $file->upsert(['PLATFORM_CLIENT_SECRET' => $unsafe]);
                $this->fail('unsafe value accepted: ' . json_encode($unsafe));
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertFileDoesNotExist($this->dir . '/x.env');
        $this->expectException(\InvalidArgumentException::class);
        $file->upsert(['lower_case' => 'x']);
    }
}
