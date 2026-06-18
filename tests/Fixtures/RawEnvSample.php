<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use StoneScriptPHP\Env;

/**
 * Fixture demonstrating the RawEnvAccessRule advisory.
 *
 * The class is intentionally excluded from the framework's own phpstan.neon
 * analysis; it is fed to PHPStan explicitly in RawEnvAccessRuleTest to assert
 * the rule fires (and is silenced).
 */
final class RawEnvSample
{
    /** SHOULD flag: raw getenv() for a secret. */
    public function flagged(): ?string
    {
        return getenv('DB_PASSWORD') ?: null;
    }

    /** SHOULD flag: raw $_ENV superglobal access. */
    public function flaggedEnvArray(): mixed
    {
        return $_ENV['REDIS_PASSWORD'] ?? null;
    }

    /** SHOULD flag: raw $_SERVER superglobal access. */
    public function flaggedServerArray(): mixed
    {
        return $_SERVER['HTTP_HOST'] ?? null;
    }

    /** SHOULD flag: putenv(). */
    public function flaggedPutenv(): void
    {
        putenv('FOO=bar');
    }

    /** SHOULD NOT flag: inline-silenced raw getenv(). */
    public function silencedInline(): ?string
    {
        /** @phpstan-ignore stonescriptphp.rawEnvAccess */
        return getenv('DB_PASSWORD') ?: null;
    }

    /** SHOULD NOT flag: uses the central Env path. */
    public function viaEnv(): ?string
    {
        return Env::secret('DB_PASSWORD');
    }
}
