<?php
/**
 * StoneScriptPHP CLI Helper - configuration lookup shared by CLI commands.
 *
 * The framework loads `.env` with Dotenv::createImmutable(), which fills $_ENV / $_SERVER but
 * NOT the process environment, so a bare getenv() never sees values that live in `.env`. CLI
 * commands therefore read configuration through the framework's Env (the same chain the running
 * application uses): real environment variable, then `<NAME>_FILE`, then `/run/secrets/<name>`,
 * with `.env` as the lowest-priority source.
 */

use StoneScriptPHP\Env;

/**
 * Load the application environment once. Exits 1 with the reason when the configuration cannot be
 * loaded at all (unreadable / unparseable `.env`). A mere validation failure (a required gateway
 * key not set) is tolerated: Env keeps the resolved instance, and each command validates the keys
 * it actually needs.
 */
function cliLoadEnv(): void
{
    // Cheap when already loaded (get_instance() returns the singleton); not memoised here so a
    // reset of Env (tests) is honoured.
    try {
        Env::get_instance();
    } catch (\Throwable $e) {
        if (Env::bootFailure() === null) {
            fwrite(STDERR, "ERROR: " . ($e instanceof \Exception ? $e->getMessage() : get_class($e)) . "\n");
            exit(1);
        }
    }
}

/**
 * First non-empty value among the given variable names, resolved through Env. Names are tried
 * in the order given, so the caller states precedence explicitly.
 */
function cliEnv(string ...$names): ?string
{
    cliLoadEnv();
    foreach ($names as $name) {
        $value = Env::secret($name);
        if ($value !== null && $value !== '') {
            return $value;
        }
    }
    return null;
}

/**
 * Resolve a setting that has a legacy CLI name and an application name. Equal values, or only one
 * set, resolve as expected (legacy first). When BOTH are set to different non-empty values the
 * command stops with a non-zero exit (1 unless the caller says otherwise) naming the two keys (never the values): silently picking one could
 * register or migrate the wrong platform/schema.
 */
function cliEnvPair(string $legacyName, string $appName, int $conflictExitCode = 1): ?string
{
    $legacy = cliEnv($legacyName);
    $app = cliEnv($appName);
    if ($legacy !== null && $app !== null && $legacy !== $app) {
        fwrite(STDERR, "ERROR: conflicting configuration: {$legacyName} and {$appName} are both set, to different values. "
            . "Unset one of them (they name the same setting).\n");
        exit($conflictExitCode);
    }
    return $legacy ?? $app;
}
