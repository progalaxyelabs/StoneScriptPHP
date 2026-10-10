<?php

/**
 * php stone tenants:check-ids [--file=<path>|-] [--platform=<code>] [--schema=<name>]
 *
 * READ-ONLY pre-flight for the tenant-id rule (see StoneScriptPHP\Tenancy\TenantIdRule). Reads stored tenant
 * ids, one per line, from --file (or stdin with `-` / piped input) and lists every id that would be refused
 * at provision time, and every group of ids that sanitise onto ONE database name. It touches no database and
 * calls no gateway. Export the ids from wherever the platform keeps them, e.g.
 *     psql -At -c "select tenant_id from tenants" | php stone tenants:check-ids --platform=myapp --schema=tenant
 *
 * --sample-generator[=N] additionally GENERATES N ids (default 200) with the id generator the platform actually uses
 * (`ProvisionTenantRoute::generateUuid()`, or `--generator-class=<FQCN>` for the platform's own subclass of it),
 * validates each against the rule and the 63-byte database name, and checks the sample for collisions. The stored
 * ids are only the past; a generator that can emit a refused id breaks provisioning later. With
 * --sample-generator and no --file, stdin is not read. BLOCKING pre-flight: do not roll out until it exits 0.
 *
 * --platform defaults to PLATFORM_ID / DB_GATEWAY_PLATFORM, --schema to TENANT_SCHEMA_NAME / SCHEMA_NAME
 * (both feed the 63-byte database-name check). Exit code: 0 all fine, 1 violations found, 2 usage error.
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
require_once rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use StoneScriptPHP\Tenancy\TenantIdRule;

$opt = ['file' => null, 'platform' => getenv('PLATFORM_ID') ?: (getenv('DB_GATEWAY_PLATFORM') ?: ''), 'schema' => getenv('TENANT_SCHEMA_NAME') ?: (getenv('SCHEMA_NAME') ?: '')];
$sample = null;
$generatorClass = \StoneScriptPHP\Auth\ExternalAuth\Routes\ProvisionTenantRoute::class;
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (preg_match('/^--(file|platform|schema)=(.*)$/', $arg, $m)) {
        $opt[$m[1]] = $m[2];
    } elseif ($arg === '--sample-generator') {
        $sample = 200;
    } elseif (preg_match('/^--sample-generator=(\d+)$/', $arg, $m)) {
        $sample = max(1, (int) $m[1]);
    } elseif (preg_match('/^--generator-class=(.+)$/', $arg, $m)) {
        $generatorClass = ltrim($m[1], '\\');
    }
}
if ($opt['platform'] === '' || $opt['schema'] === '') {
    fwrite(STDERR, "ERROR: --platform and --schema (or PLATFORM_ID and TENANT_SCHEMA_NAME/SCHEMA_NAME) are required for the database-name length check.\n");
    exit(2);
}

if ($sample !== null && $opt['file'] === null) {
    $raw = ''; // generator sampling only: do not block on stdin
} else {
    $raw = ($opt['file'] === null || $opt['file'] === '-')
        ? stream_get_contents(STDIN)
        : (is_readable((string) $opt['file']) ? file_get_contents((string) $opt['file']) : false);
}
if ($raw === false || $raw === null) {
    fwrite(STDERR, "ERROR: cannot read tenant ids (use --file=<path> or pipe them in).\n");
    exit(2);
}

$ids = [];
foreach (preg_split('/\r?\n/', $raw) as $line) {
    if ($line !== '') {
        $ids[] = $line; // exactly as stored: do NOT trim, a stray space is a violation worth reporting
    }
}

$generated = [];
if ($sample !== null) {
    if (!class_exists($generatorClass) || !method_exists($generatorClass, 'generateUuid')) {
        fwrite(STDERR, "ERROR: generator class $generatorClass not found or has no generateUuid().\n");
        exit(2);
    }
    $rc = new \ReflectionClass($generatorClass);
    $gen = $rc->newInstanceWithoutConstructor();
    $m = new \ReflectionMethod($generatorClass, 'generateUuid');
    $m->setAccessible(true);
    for ($i = 0; $i < $sample; $i++) {
        $generated[] = (string) $m->invoke($gen);
    }
    printf("sampled %d id(s) from %s::generateUuid() (e.g. %s)\n", $sample, $generatorClass, $generated[0]);
}

$bad = 0;
$byDb = [];
$genBad = 0;
foreach ($generated as $id) {
    $why = TenantIdRule::violation($id, $opt['platform'], $opt['schema']);
    if ($why !== null) {
        $genBad++;
        echo "GENERATOR-REJECT  " . json_encode($id) . "  - $why\n";
    }
    $byDb[TenantIdRule::databaseName($opt['platform'], $opt['schema'], $id)][] = $id;
}
foreach ($ids as $id) {
    $why = TenantIdRule::violation($id, $opt['platform'], $opt['schema']);
    if ($why !== null) {
        $bad++;
        echo "REJECT  " . json_encode($id) . "  - $why\n";
    }
    $byDb[TenantIdRule::databaseName($opt['platform'], $opt['schema'], $id)][] = $id;
}
$collisions = 0;
foreach ($byDb as $db => $group) {
    if (count(array_unique($group)) > 1) {
        $collisions++;
        echo "COLLIDE " . $db . "  <- " . implode(', ', array_map('json_encode', array_unique($group))) . "\n";
    }
}

printf("%d id(s) checked, %d rejected, %d collision group(s).\n", count($ids), $bad, $collisions);
if ($sample !== null) {
    printf("generator sample: %d id(s), %d rejected.\n", count($generated), $genBad);
}
exit(($bad + $collisions + $genBad) > 0 ? 1 : 0);
