<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Platform;

use StoneScriptPHP\Tenancy\Provisioning\AuthTenancyClient;
use StoneScriptPHP\Tenancy\Provisioning\ProvisioningException;

/**
 * `php stone platform:register --name "<name>" --slug <slug>`
 *
 * Registers this platform with the auth service (design 4.1):
 *  - the OPERATOR token comes only from a separate operator env file (default ~/.stone/operator.env,
 *    or --operator-env=<file>) or from the STONE_OPERATOR_TOKEN variable given to this one command. It
 *    is never read from the application's runtime .env, and a file that is the project's .env, or that
 *    other users can read, is refused;
 *  - auth returns {platform_id, client_id, client_secret}; the credential is written into the runtime
 *    secrets file (default <project>/.env) BEFORE the platform row is updated, so an interrupted run can
 *    always be repeated (a retry returns the same platform and no second secret);
 *  - only platform_id and the slug are ever printed. A secret never reaches stdout, stderr or a log.
 *
 * Idempotent: the idempotency key defaults to "register:<slug>".
 */
final class PlatformRegisterCommand
{
    public const TOKEN_VAR = OperatorTokenSource::TOKEN_VAR;

    /** @var callable(string):void */
    private $stdout;
    /** @var callable(string):void */
    private $stderr;
    /** @var callable(string):AuthTenancyClient */
    private $clientFactory;

    /**
     * @param callable(string):void $stdout
     * @param callable(string):void $stderr
     * @param callable(string):AuthTenancyClient $clientFactory builds the client for an auth base URL
     */
    public function __construct(callable $stdout, callable $stderr, callable $clientFactory, private readonly PlatformRowStore $rows)
    {
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param list<string> $args arguments after the command name
     * @param array<string,string> $processEnv the process environment (not the application .env)
     * @return int exit status
     */
    public function run(array $args, array $processEnv, string $home, string $projectRoot, ?string $projectAuthUrl): int
    {
        $opts = $this->parse($args);
        if ($opts === null) {
            return $this->fail('Usage: php stone platform:register --name "<name>" --slug <slug> [--auth-url=<url>] [--operator-env=<file>] [--secrets-file=<file>] [--idempotency-key=<key>]');
        }
        if (isset($opts['help'])) {
            ($this->stdout)("Registers this platform with the auth service.\n"
                . "  --name        display name\n  --slug        lowercase a-z0-9 words joined by _, at most 19 chars\n"
                . "  --auth-url    auth service URL (default: AUTH_SERVICE_URL of this project)\n"
                . "  --operator-env  operator env file (default ~/.stone/operator.env); or set " . self::TOKEN_VAR . " for this one command\n"
                . "  --secrets-file  where the client credential is written (default: <project>/.env)\n");
            return 0;
        }

        $name = trim((string) ($opts['name'] ?? ''));
        $slug = (string) ($opts['slug'] ?? '');
        if ($name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1f]/', $name) === 1) {
            return $this->fail('--name is required (at most 255 characters, no control characters)');
        }
        if (!AuthTenancyClient::isValidSlug($slug)) {
            return $this->fail('--slug must be lower-case a-z0-9 words joined by single underscores, at most ' . AuthTenancyClient::MAX_SLUG_LENGTH . ' characters (so {slug}_tenant_{uuid} fits a 63-byte database name)');
        }

        $authUrl = (string) ($opts['auth-url'] ?? $projectAuthUrl ?? '');
        if ($authUrl === '') {
            return $this->fail('No auth service URL: pass --auth-url or set AUTH_SERVICE_URL');
        }

        $secretsPath = (string) ($opts['secrets-file'] ?? rtrim($projectRoot, '/') . '/.env');
        $token = $this->operatorToken($opts, $processEnv, $home, rtrim($projectRoot, '/') . '/.env', $secretsPath);
        if (is_int($token)) {
            return $token;
        }

        $key = (string) ($opts['idempotency-key'] ?? 'register:' . $slug);

        try {
            $registration = ($this->clientFactory)($authUrl)->registerPlatform($token, $name, $slug, $key);
        } catch (ProvisioningException $e) {
            return $this->fail('Registration failed: ' . $e->getMessage());
        } catch (\Throwable) {
            return $this->fail('Registration failed');
        }

        $secrets = new SecretsFile($secretsPath);
        try {
            // The platform credential goes to its OWN variables. EXTERNAL_AUTH_CLIENT_SECRET is the legacy 14.x
            // X-Platform-Secret and is never written or replaced here.
            $values = ['PLATFORM_CLIENT_ID' => $registration['client_id'], 'PLATFORM_UUID' => $registration['platform_id']];
            if ($registration['client_secret'] !== null) {
                $values['PLATFORM_CLIENT_SECRET'] = $registration['client_secret'];
            } elseif ($secrets->get('PLATFORM_CLIENT_ID') !== $registration['client_id'] || $secrets->get('PLATFORM_CLIENT_SECRET') === null) {
                // Auth never shows a secret twice and none for this client is stored: say so, do not guess.
                return $this->fail('Auth did not return a client secret (it was issued earlier) and none for this client is stored in the secrets file. Rotate the platform credential, then run this command again.');
            }
            $secrets->upsert($values);
        } catch (\Throwable) {
            return $this->fail('Could not write the client credential to the secrets file; nothing was recorded in the database. Fix the file and run the command again.');
        }

        try {
            $this->rows->register($registration['platform_id'], $name, $slug, 'external');
        } catch (\Throwable) {
            return $this->fail('The credential was saved, but the platform row could not be updated in the main database (is the tenancy schema migrated?). Run the command again.');
        }

        ($this->stdout)("platform_id: {$registration['platform_id']}\nslug: $slug\n");
        return 0;
    }

    /** @return int|string exit status on a refusal, otherwise the operator token */
    private function operatorToken(array $opts, array $processEnv, string $home, string $projectEnvPath, string $secretsPath): int|string
    {
        $r = OperatorTokenSource::resolve($opts, $processEnv, $home, $projectEnvPath, $secretsPath);
        return isset($r['token']) ? $r['token'] : $this->fail($r['error']);
    }

    /** @return array<string,string>|null */
    private function parse(array $args): ?array
    {
        $opts = [];
        $known = ['name', 'slug', 'auth-url', 'operator-env', 'secrets-file', 'idempotency-key'];
        for ($i = 0; $i < count($args); $i++) {
            $arg = (string) $args[$i];
            if ($arg === '--help' || $arg === '-h') {
                $opts['help'] = '1';
                continue;
            }
            if (preg_match('/\A--([a-z-]+)(?:=(.*))?\z/s', $arg, $m) !== 1 || !in_array($m[1], $known, true)) {
                return null;
            }
            if (isset($m[2])) {
                $opts[$m[1]] = $m[2];
            } elseif (isset($args[$i + 1]) && !str_starts_with((string) $args[$i + 1], '--')) {
                $opts[$m[1]] = (string) $args[++$i];
            } else {
                return null;
            }
        }
        return $opts;
    }

    private function fail(string $message): int
    {
        ($this->stderr)('ERROR: ' . $message . "\n");
        return 1;
    }
}
