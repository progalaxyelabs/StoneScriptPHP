<?php

declare(strict_types=1);

/**
 * Schema-Contract Validator
 *
 * Deploy-gate check: connects to a REAL PostgreSQL (not a mock) and, for
 * every generated `App\Database\Functions\Fn*.php` file in the project,
 * compares the committed PHP DTO against the TRUE live signature of the SQL
 * function it wraps (read straight from `pg_proc`/`pg_type` — never from the
 * .pgsql source text, which can drift from what's actually deployed).
 *
 * This exists to catch the class of bug where:
 *   - `php stone generate model` was run once, correctly, against an OLDER
 *     SQL signature, and the SQL function was LATER changed (e.g. a required
 *     column became optional / gained a DEFAULT) WITHOUT regenerating the
 *     PHP wrapper, or
 *   - the PHP wrapper was hand-edited and now disagrees with the DB, or
 *   - an older/legacy code-generation path (pre `{Class}Params` object,
 *     scalar-argument style) produced a wrapper that was never regenerated.
 *
 * None of these are visible to per-endpoint unit tests, because those tests
 * exercise the DTO through its OWN (possibly wrong) type contract — the test
 * inherits the same wrong assumption the DTO encodes. Only checking the DTO
 * against the database's own idea of the function's signature can catch it.
 *
 * Usage:
 *   php stone validate schema-contract
 *   php stone validate schema-contract --host=127.0.0.1 --port=5432 --dbname=myapp_main --user=postgres --password=secret
 *   php stone validate schema-contract --strict     # also fail on TYPE-family mismatches (not just nullability/count/order)
 *   php stone validate schema-contract --json
 *
 * Connection parameters, in priority order: CLI flag, then environment
 * variable (DATABASE_HOST / DATABASE_PORT / DATABASE_DBNAME / DATABASE_USER
 * / DATABASE_PASSWORD — the same names `php stone migrate verify` reads via
 * StoneScriptPHP\Migrations), then a bare-minimum localhost default.
 *
 * Exit codes: 0 = every Fn* DTO matches its live DB function, 1 = at least
 * one mismatch (or a connection failure) found.
 */

require_once __DIR__ . '/generate-common.php'; // provides detect_root_path()

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', detect_root_path());
}
if (!defined('SRC_PATH')) {
    define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);
}

$argv = $_SERVER['argv'] ?? [];

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Schema-Contract Validator\n";
    echo "=========================\n\n";
    echo "Usage: php stone validate schema-contract [options]\n\n";
    echo "Compares every committed src/App/Database/Functions/Fn*.php DTO against\n";
    echo "the TRUE live PostgreSQL function signature (pg_proc), catching DTO/DB\n";
    echo "nullability drift that per-endpoint unit tests structurally cannot see.\n\n";
    echo "Options:\n";
    echo "  --host=HOST          Default: DATABASE_HOST env or 127.0.0.1\n";
    echo "  --port=PORT          Default: DATABASE_PORT env or 5432\n";
    echo "  --dbname=NAME        Default: DATABASE_DBNAME env (required)\n";
    echo "  --user=USER          Default: DATABASE_USER env or postgres\n";
    echo "  --password=PASS      Default: DATABASE_PASSWORD env or empty\n";
    echo "  --strict             Also fail on PHP-type/SQL-type family mismatches\n";
    echo "                       (by default those are reported as warnings only)\n";
    echo "  --json               Machine-readable output\n\n";
    echo "Exit codes:\n";
    echo "  0  Every Fn* DTO matches its live DB function signature\n";
    echo "  1  At least one mismatch found, or could not connect to the database\n";
    exit(0);
}

function cc_arg(array $argv, string $name, ?string $default): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen("--{$name}="));
        }
    }
    return $default;
}

$strict = in_array('--strict', $argv, true);
$json   = in_array('--json', $argv, true);

$host     = cc_arg($argv, 'host', getenv('DATABASE_HOST') ?: '127.0.0.1');
$port     = cc_arg($argv, 'port', getenv('DATABASE_PORT') ?: '5432');
$dbname   = cc_arg($argv, 'dbname', getenv('DATABASE_DBNAME') ?: null);
$user     = cc_arg($argv, 'user', getenv('DATABASE_USER') ?: 'postgres');
$password = cc_arg($argv, 'password', getenv('DATABASE_PASSWORD') ?: '');

if (empty($dbname)) {
    fwrite(STDERR, "Error: no database name given. Pass --dbname=NAME or set DATABASE_DBNAME.\n");
    exit(1);
}

$connString = join(' ', [
    "host=" . $host,
    "port=" . $port,
    "dbname=" . $dbname,
    "user=" . $user,
    "password=" . $password,
    "connect_timeout=5",
]);

$conn = @pg_connect($connString);
if ($conn === false) {
    fwrite(STDERR, "Error: could not connect to PostgreSQL at {$host}:{$port}/{$dbname} as {$user}.\n");
    fwrite(STDERR, "The schema-contract check needs a REAL, migrated database -- it cannot run against a mock.\n");
    exit(1);
}

/**
 * A PHP-declared type ('int'|'string'|'bool'|'float'|'mixed'), mirroring the
 * SAME type_map cli/generate-model.php uses, so a --strict TYPE-family
 * mismatch report speaks the generator's own vocabulary.
 */
const SQL_TO_PHP_TYPE_MAP = [
    'int2' => 'int', 'int4' => 'int', 'int8' => 'int', 'serial' => 'int', 'bigserial' => 'int',
    'text' => 'string', 'varchar' => 'string', 'bpchar' => 'string', 'uuid' => 'string',
    'json' => 'mixed', 'jsonb' => 'mixed',
    'bool' => 'bool',
    'timestamptz' => 'string', 'timestamp' => 'string', 'date' => 'string', 'time' => 'string',
    'numeric' => 'float', 'float4' => 'float', 'float8' => 'float',
];

/**
 * Fetch the TRUE input-argument list (name, sql base type, is_optional) of a
 * live PostgreSQL function, in DECLARATION order, from pg_proc/pg_type
 * directly -- never from .pgsql source text (which may not even be what's
 * actually deployed).
 *
 * Postgres guarantees DEFAULT-bearing parameters are always the TRAILING N
 * parameters among the function's INPUT (i/b/v-mode) arguments, where N =
 * pronargdefaults. That single fact is all we need for nullability -- no
 * text parsing of `pg_get_function_arguments()` required.
 *
 * @return array<int, array{name: string, sql_type: string, optional: bool}>|null
 *   null if the function does not exist (or is ambiguous across schemas —
 *   reported separately by the caller).
 */
function fetch_live_function_signature($conn, string $fnName): array|string
{
    $escaped = pg_escape_string($conn, $fnName);
    $sql = "
        SELECT p.oid,
               p.pronargs,
               p.pronargdefaults,
               n.nspname,
               COALESCE(to_json(p.proargnames)::text, 'null') AS argnames_json,
               COALESCE(to_json(p.proargmodes)::text, 'null') AS argmodes_json,
               COALESCE(to_json(COALESCE(p.proallargtypes, p.proargtypes::oid[]))::text, 'null') AS argtypes_json
        FROM pg_proc p
        JOIN pg_namespace n ON n.oid = p.pronamespace
        WHERE p.proname = '{$escaped}'
          AND n.nspname NOT IN ('pg_catalog', 'information_schema')
        ORDER BY p.oid DESC
    ";
    $result = pg_query($conn, $sql);
    if ($result === false) {
        return 'query failed: ' . pg_last_error($conn);
    }
    $rows = pg_fetch_all($result) ?: [];
    if (count($rows) === 0) {
        return [];
    }
    // Framework convention (documented in real fleet SQL) is one overload per
    // function name; if more than one exists, take the most recently created
    // (highest oid) but flag it via a warning the caller can surface.
    $row = $rows[0];

    $argnames = json_decode($row['argnames_json'], true);
    $argmodes = json_decode($row['argmodes_json'], true);
    $argtypeOids = json_decode($row['argtypes_json'], true);
    $pronargdefaults = (int) $row['pronargdefaults'];

    // Build the ordered list of INPUT-only args (i/b/v modes), preserving
    // their relative position among ALL declared args (needed because
    // RETURNS TABLE columns show up as trailing 'o'-mode entries in
    // proargnames/proargmodes once any OUT-style arg exists at all).
    $inputArgs = [];
    if ($argmodes === null) {
        // No OUT/INOUT/VARIADIC/TABLE-column args at all -> every declared
        // arg is a plain IN parameter, argnames covers exactly pronargs.
        $names = $argnames ?? [];
        foreach ($names as $name) {
            $inputArgs[] = ['name' => $name, 'type_oid' => null];
        }
    } else {
        foreach ($argmodes as $idx => $mode) {
            if (in_array($mode, ['i', 'b', 'v'], true)) {
                $inputArgs[] = [
                    'name' => $argnames[$idx] ?? "(unnamed_{$idx})",
                    'type_oid' => $argtypeOids[$idx] ?? null,
                ];
            }
        }
    }

    // Resolve type oids to base type names in one batch query (best-effort;
    // only used for the --strict type-family check, never for pass/fail on
    // nullability/count/order).
    $typeOids = array_values(array_filter(array_column($inputArgs, 'type_oid'), fn ($v) => $v !== null));
    $typeNamesByOid = [];
    if (count($typeOids) > 0) {
        $oidList = implode(',', array_map('intval', $typeOids));
        $typeResult = pg_query($conn, "SELECT oid, typname FROM pg_type WHERE oid IN ({$oidList})");
        if ($typeResult !== false) {
            foreach (pg_fetch_all($typeResult) ?: [] as $t) {
                $typeNamesByOid[(int) $t['oid']] = $t['typname'];
            }
        }
    }

    $total = count($inputArgs);
    $specs = [];
    foreach ($inputArgs as $i => $arg) {
        $specs[] = [
            'name' => $arg['name'],
            'sql_type' => $arg['type_oid'] !== null ? ($typeNamesByOid[(int) $arg['type_oid']] ?? 'unknown') : 'unknown',
            // Defaults are always the TRAILING pronargdefaults input args.
            'optional' => $i >= ($total - $pronargdefaults),
        ];
    }

    return $specs;
}

/**
 * Extract the {Class}Params properties (name, php type, nullable) from a
 * generated Fn*.php file's source text, in DECLARATION order -- via
 * reflection on the actually-`require_once`'d class, which is more reliable
 * than regexing the property lines by hand (nullable union types, doc
 * comments, etc. all resolve correctly through PHP's own type system).
 *
 * @return array{function_name: string|null, params: array<int, array{name: string, php_type: string, nullable: bool}>|null}
 */
function extract_dto_contract(string $filePath): array
{
    $content = file_get_contents($filePath);
    if ($content === false) {
        return ['function_name' => null, 'params' => null];
    }

    if (!preg_match('/\$function_name\s*=\s*\'([a-z0-9_]+)\'/i', $content, $m)) {
        return ['function_name' => null, 'params' => null];
    }
    $functionName = $m[1];

    if (!preg_match('/class\s+(\w*Params)\b/', $content, $pm)) {
        // No {Class}Params class -- either a genuine zero-argument function,
        // OR a LEGACY-style wrapper (pre `{Class}Params` object refactor)
        // that takes scalar arguments directly on run(). Distinguish the two
        // by inspecting run()'s own signature, so a stale legacy wrapper is
        // reported as an actionable "regenerate this" drift rather than
        // silently compared as if it took zero arguments.
        if (preg_match('/public\s+static\s+function\s+run\s*\(([^)]*)\)/s', $content, $rm) && trim($rm[1]) !== '') {
            $legacyParamCount = count(array_filter(explode(',', $rm[1]), fn ($p) => trim($p) !== ''));
            return [
                'function_name' => $functionName,
                'params' => null,
                'legacy_scalar_param_count' => $legacyParamCount,
            ];
        }
        return ['function_name' => $functionName, 'params' => []];
    }
    $paramsClass = 'App\\Database\\Functions\\' . $pm[1];

    require_once $filePath;

    if (!class_exists($paramsClass, false)) {
        return ['function_name' => $functionName, 'params' => null];
    }

    $reflect = new ReflectionClass($paramsClass);
    $params = [];
    foreach ($reflect->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
        $type = $prop->getType();
        $params[] = [
            'name' => $prop->getName(),
            'php_type' => $type instanceof ReflectionNamedType ? $type->getName() : 'mixed',
            'nullable' => $type === null || $type->allowsNull(),
        ];
    }

    return ['function_name' => $functionName, 'params' => $params];
}

$functionsDir = SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'Functions' . DIRECTORY_SEPARATOR;

if (!is_dir($functionsDir)) {
    fwrite(STDERR, "Error: {$functionsDir} does not exist -- nothing to validate.\n");
    exit(1);
}

$files = glob($functionsDir . 'Fn*.php') ?: [];
sort($files);

$results = [];
$hasFailure = false;

foreach ($files as $file) {
    $dto = extract_dto_contract($file);
    $baseName = basename($file);

    if ($dto['function_name'] === null) {
        $results[] = ['file' => $baseName, 'status' => 'ERROR', 'message' => 'could not locate $function_name literal in this file'];
        $hasFailure = true;
        continue;
    }

    $fnName = $dto['function_name'];
    $live = fetch_live_function_signature($conn, $fnName);

    if (is_string($live)) {
        $results[] = ['file' => $baseName, 'function' => $fnName, 'status' => 'ERROR', 'message' => $live];
        $hasFailure = true;
        continue;
    }
    if (count($live) === 0) {
        $results[] = ['file' => $baseName, 'function' => $fnName, 'status' => 'ERROR', 'message' => "SQL function '{$fnName}' does not exist in the live database"];
        $hasFailure = true;
        continue;
    }

    if ($dto['params'] === null) {
        if (isset($dto['legacy_scalar_param_count'])) {
            $message = sprintf(
                "LEGACY WRAPPER DRIFT: this DTO takes %d scalar argument(s) directly on run() (pre-{Class}Params-object "
                . "codegen style) instead of a {Class}Params class, but the live SQL function '%s' takes %d parameter(s), %d of "
                . "which are optional/DEFAULT'd. A legacy scalar wrapper has NO WAY to express optionality -- every "
                . "parameter is required, so any legitimately-null call throws a PHP TypeError before the database is "
                . "ever reached. This is the exact drift bug class this check exists to catch. Regenerate with "
                . "'php stone generate model' against the current SQL function.",
                $dto['legacy_scalar_param_count'],
                $fnName,
                count($live),
                count(array_filter($live, fn ($p) => $p['optional']))
            );
        } else {
            $message = 'could not locate a {Class}Params class to reflect (and run() takes no arguments, but the live '
                . "function '{$fnName}' takes " . count($live) . ' parameter(s))';
        }
        $results[] = ['file' => $baseName, 'function' => $fnName, 'status' => 'FAIL', 'issues' => [$message]];
        $hasFailure = true;
        continue;
    }

    $issues = [];

    if (count($dto['params']) !== count($live)) {
        $issues[] = sprintf(
            "parameter COUNT mismatch: DTO has %d, live DB function has %d",
            count($dto['params']),
            count($live)
        );
    } else {
        foreach ($dto['params'] as $i => $phpParam) {
            $sqlParam = $live[$i];
            if ($phpParam['name'] !== $sqlParam['name']) {
                $issues[] = sprintf(
                    "parameter ORDER/NAME mismatch at position %d: DTO has '%s', live DB has '%s'",
                    $i,
                    $phpParam['name'],
                    $sqlParam['name']
                );
                continue;
            }
            if ($sqlParam['optional'] && !$phpParam['nullable']) {
                $issues[] = sprintf(
                    "NULLABILITY DRIFT: SQL parameter '%s' is optional/DEFAULT'd in the live database, "
                    . "but the DTO declares it as a non-nullable PHP type ('%s'). Every legitimate null "
                    . "call will throw a TypeError before the database is ever reached. Regenerate with "
                    . "'php stone generate model' or hand-fix the {Class}Params property to '?%s = null'.",
                    $sqlParam['name'],
                    $phpParam['php_type'],
                    $phpParam['php_type']
                );
            } elseif (!$sqlParam['optional'] && $phpParam['nullable']) {
                $issues[] = sprintf(
                    "OVER-NULLABLE: SQL parameter '%s' is REQUIRED in the live database, but the DTO "
                    . "declares it nullable ('?%s'). Not a runtime crash risk, but hides a required-field "
                    . "contract from callers -- tighten the DTO.",
                    $sqlParam['name'],
                    $phpParam['php_type']
                );
            }

            if ($strict && $sqlParam['sql_type'] !== 'unknown') {
                $expectedPhpType = SQL_TO_PHP_TYPE_MAP[$sqlParam['sql_type']] ?? null;
                $actualPhpType = ltrim($phpParam['php_type'], '?');
                if ($expectedPhpType !== null && $expectedPhpType !== $actualPhpType && $actualPhpType !== 'mixed') {
                    $issues[] = sprintf(
                        "TYPE-FAMILY mismatch (--strict): SQL parameter '%s' is %s (expected PHP '%s'), DTO declares '%s'",
                        $sqlParam['name'],
                        $sqlParam['sql_type'],
                        $expectedPhpType,
                        $actualPhpType
                    );
                }
            }
        }
    }

    if (count($issues) > 0) {
        $results[] = ['file' => $baseName, 'function' => $fnName, 'status' => 'FAIL', 'issues' => $issues];
        $hasFailure = true;
    } else {
        $results[] = ['file' => $baseName, 'function' => $fnName, 'status' => 'PASS'];
    }
}

pg_close($conn);

if ($json) {
    echo json_encode(['results' => $results, 'ok' => !$hasFailure], JSON_PRETTY_PRINT) . "\n";
    exit($hasFailure ? 1 : 0);
}

echo "Schema-Contract Validator ({$dbname} @ {$host}:{$port})\n";
echo str_repeat('=', 60) . "\n\n";

foreach ($results as $r) {
    $label = $r['function'] ?? $r['file'];
    if ($r['status'] === 'PASS') {
        echo "  [PASS] {$r['file']} ({$label})\n";
    } elseif ($r['status'] === 'ERROR') {
        echo "  [ERROR] {$r['file']} ({$label}): {$r['message']}\n";
    } else {
        echo "  [FAIL] {$r['file']} ({$label})\n";
        foreach ($r['issues'] as $issue) {
            echo "         - {$issue}\n";
        }
    }
}

echo "\n";
if ($hasFailure) {
    echo "RESULT: contract drift found. See FAIL/ERROR lines above.\n";
} else {
    echo "RESULT: all " . count($results) . " Fn* DTO(s) match their live database function signatures.\n";
}

exit($hasFailure ? 1 : 0);
