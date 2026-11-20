<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Platform;

/**
 * Where an operator command gets the operator token: ONLY a separate operator env file (default
 * ~/.stone/operator.env or --operator-env=<file>) or the STONE_OPERATOR_TOKEN variable of that one command.
 * Never the application's runtime .env: that file (or the runtime secrets file) is refused, and so is an
 * operator file other users can read.
 */
final class OperatorTokenSource
{
    public const TOKEN_VAR = 'STONE_OPERATOR_TOKEN';

    private function __construct()
    {
    }

    /**
     * @param array<string,string> $opts parsed command options (operator-env)
     * @param array<string,string> $processEnv the process environment (not the application .env)
     * @return array{token:string}|array{error:string}
     */
    public static function resolve(array $opts, array $processEnv, string $home, string $projectEnvPath, string $secretsPath): array
    {
        $file = $opts['operator-env'] ?? null;
        $fromEnv = (string) ($processEnv[self::TOKEN_VAR] ?? '');
        if ($file === null && $fromEnv !== '') {
            return ['token' => $fromEnv];
        }

        $file = (string) ($file ?? rtrim($home, '/') . '/.stone/operator.env');
        if (!is_file($file) || !is_readable($file)) {
            return ['error' => 'No operator token: create ' . $file . ' containing ' . self::TOKEN_VAR . '=<token> (mode 600), or run this command with ' . self::TOKEN_VAR . ' set'];
        }
        $real = realpath($file);
        foreach ([$projectEnvPath, $secretsPath] as $forbidden) {
            if ($real !== false && is_file($forbidden) && realpath($forbidden) === $real) {
                return ['error' => 'The operator token must not live in the application .env or the runtime secrets file; use a separate operator env file'];
            }
        }
        if ((fileperms($file) & 0077) !== 0) {
            return ['error' => "The operator env file $file is readable by other users; run: chmod 600 $file"];
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?' . self::TOKEN_VAR . '\s*=\s*(.*)$/', $line, $m) === 1) {
                $value = trim(trim($m[1]), "\"'");
                if ($value !== '') {
                    return ['token' => $value];
                }
            }
        }
        return ['error' => "$file does not define " . self::TOKEN_VAR];
    }
}
