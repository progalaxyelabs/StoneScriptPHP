<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Platform;

use StoneScriptPHP\Tenancy\Provisioning\AuthV2Client;
use StoneScriptPHP\Tenancy\Provisioning\ProvisioningException;
use StoneScriptPHP\Tenancy\Provisioning\Uuid;

/**
 * `php stone platform:rotate-credential [--platform-id <uuid>]`
 *
 * Asks auth to add a `next` credential for this platform (auth keeps two live credentials so a secret can be
 * replaced without downtime). The new client id and secret are written to AUTH_V2_CLIENT_ID_NEXT and
 * AUTH_V2_CLIENT_SECRET_NEXT in the runtime secrets file; the credential in use (AUTH_V2_CLIENT_*) is not touched.
 * To finish the rotation: deploy with the NEXT values moved into AUTH_V2_CLIENT_ID / AUTH_V2_CLIENT_SECRET, then
 * the operator revokes the old client id at auth (revoking the current credential promotes the next one).
 *
 * Same operator-token rules as platform:register (separate operator env, https, never printed). The secret is
 * only shown by the call that issues it: a repeated call with the same idempotency key returns none, and the
 * command says so.
 */
final class PlatformRotateCredentialCommand
{
    /** @var callable(string):void */
    private $stdout;
    /** @var callable(string):void */
    private $stderr;
    /** @var callable(string):AuthV2Client */
    private $clientFactory;

    /**
     * @param callable(string):void $stdout
     * @param callable(string):void $stderr
     * @param callable(string):AuthV2Client $clientFactory
     */
    public function __construct(callable $stdout, callable $stderr, callable $clientFactory)
    {
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param list<string> $args
     * @param array<string,string> $processEnv
     */
    public function run(array $args, array $processEnv, string $home, string $projectRoot, ?string $projectAuthUrl, ?\DateTimeImmutable $now = null): int
    {
        $opts = [];
        $known = ['platform-id', 'auth-url', 'operator-env', 'secrets-file', 'idempotency-key'];
        for ($i = 0; $i < count($args); $i++) {
            if (preg_match('/\A--([a-z-]+)(?:=(.*))?\z/s', (string) $args[$i], $m) !== 1 || !in_array($m[1], $known, true)) {
                return $this->fail('Usage: php stone platform:rotate-credential [--platform-id <uuid>] [--auth-url=<url>] [--operator-env=<file>] [--secrets-file=<file>] [--idempotency-key=<key>]');
            }
            if (isset($m[2])) {
                $opts[$m[1]] = $m[2];
            } elseif (isset($args[$i + 1]) && !str_starts_with((string) $args[$i + 1], '--')) {
                $opts[$m[1]] = (string) $args[++$i];
            } else {
                return $this->fail('Missing value for --' . $m[1]);
            }
        }

        $secretsPath = (string) ($opts['secrets-file'] ?? rtrim($projectRoot, '/') . '/.env');
        $secrets = new SecretsFile($secretsPath);
        $platformId = (string) ($opts['platform-id'] ?? $secrets->get('PLATFORM_UUID') ?? '');
        if (!Uuid::isValid($platformId)) {
            return $this->fail('No platform id: pass --platform-id or run platform:register first (it records PLATFORM_UUID)');
        }
        $authUrl = (string) ($opts['auth-url'] ?? $projectAuthUrl ?? '');
        if ($authUrl === '') {
            return $this->fail('No auth service URL: pass --auth-url or set AUTH_SERVICE_URL');
        }
        $token = OperatorTokenSource::resolve($opts, $processEnv, $home, rtrim($projectRoot, '/') . '/.env', $secretsPath);
        if (!isset($token['token'])) {
            return $this->fail($token['error']);
        }

        $key = (string) ($opts['idempotency-key'] ?? 'rotate:' . $platformId . ':' . ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd'));
        try {
            $r = ($this->clientFactory)($authUrl)->rotateCredential($token['token'], $platformId, $key);
        } catch (ProvisioningException $e) {
            return $this->fail('Rotation failed: ' . $e->getMessage());
        } catch (\Throwable) {
            return $this->fail('Rotation failed');
        }
        if ($r['client_secret'] === null) {
            return $this->fail('Auth did not return a secret: a credential was already issued for this idempotency key (secrets are shown once). Use a new --idempotency-key if the secret was lost.');
        }

        try {
            $secrets->upsert(['AUTH_V2_CLIENT_ID_NEXT' => $r['client_id'], 'AUTH_V2_CLIENT_SECRET_NEXT' => $r['client_secret']]);
        } catch (\Throwable) {
            return $this->fail('The new credential was issued but could not be written to the secrets file. Rotate again with a new --idempotency-key.');
        }

        ($this->stdout)("platform_id: $platformId\nnext credential saved as AUTH_V2_CLIENT_ID_NEXT / AUTH_V2_CLIENT_SECRET_NEXT\n");
        return 0;
    }

    private function fail(string $message): int
    {
        ($this->stderr)('ERROR: ' . $message . "\n");
        return 1;
    }
}
