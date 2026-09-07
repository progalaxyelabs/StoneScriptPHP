<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Real, end-to-end test of `php stone validate schema-contract`
 * (cli/validate-schema-contract.php): a deploy-gate check that reads a
 * REAL live PostgreSQL function's true signature (pg_proc/pg_type) and
 * compares it against a committed `App\Database\Functions\Fn*.php` DTO,
 * catching DTO/DB nullability drift that per-endpoint unit tests structurally
 * cannot see (they inherit the DTO's own wrong type assumption).
 *
 * Requires a live PostgreSQL — gated on DATABASE_HOST exactly like
 * MigrationsTest, and skipped on the default unit run. Creates and drops its
 * OWN disposable database per test (unique name, dropped in tearDown) —
 * never touches any pre-existing database.
 *
 * @covers cli/validate-schema-contract.php
 */
final class ValidateSchemaContractCommandTest extends TestCase
{
    private const HOST = null; // resolved from env in setUp()

    private ?string $host = null;
    private string $port = '5432';
    private string $user = 'postgres';
    private string $password = '';
    private ?string $dbName = null;

    /** @var string[] */
    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!getenv('DATABASE_HOST')) {
            $this->markTestSkipped(
                'Requires a live PostgreSQL (DATABASE_HOST) — creates/drops its own disposable DB; integration test.'
            );
        }
        $this->host = getenv('DATABASE_HOST');
        $this->port = getenv('DATABASE_PORT') ?: '5432';
        $this->user = getenv('DATABASE_USER') ?: 'postgres';
        $this->password = getenv('DATABASE_PASSWORD') ?: '';
        $this->dbName = 'ssp_schema_contract_test_' . bin2hex(random_bytes(4));

        $adminConn = pg_connect($this->connString('postgres'));
        if ($adminConn === false) {
            $this->markTestSkipped('Could not connect to the admin "postgres" database to create a disposable test DB.');
        }
        pg_query($adminConn, 'CREATE DATABASE ' . pg_escape_identifier($adminConn, $this->dbName));
        pg_close($adminConn);
    }

    protected function tearDown(): void
    {
        if ($this->dbName !== null) {
            $adminConn = pg_connect($this->connString('postgres'));
            if ($adminConn !== false) {
                pg_query($adminConn, 'DROP DATABASE IF EXISTS ' . pg_escape_identifier($adminConn, $this->dbName) . ' WITH (FORCE)');
                pg_close($adminConn);
            }
        }
        foreach ($this->fixtures as $fixture) {
            $this->deleteDir($fixture);
        }
        $this->fixtures = [];
        parent::tearDown();
    }

    private function connString(string $dbName): string
    {
        return "host={$this->host} port={$this->port} dbname={$dbName} user={$this->user} password={$this->password} connect_timeout=5";
    }

    private function createSqlFunction(string $sql): void
    {
        $conn = pg_connect($this->connString($this->dbName));
        $this->assertNotFalse($conn, 'could not connect to the disposable test DB');
        $result = pg_query($conn, $sql);
        $this->assertNotFalse($result, 'failed to create fixture SQL function: ' . pg_last_error($conn));
        pg_close($conn);
    }

    private function buildFixture(): string
    {
        $frameworkRoot = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR;
        $fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssp_schemacontract_fixture_' . uniqid('', true) . DIRECTORY_SEPARATOR;
        $this->fixtures[] = $fixture;

        $vendorFrameworkDir = $fixture . 'vendor' . DIRECTORY_SEPARATOR . 'progalaxyelabs' . DIRECTORY_SEPARATOR . 'stonescriptphp' . DIRECTORY_SEPARATOR;
        mkdir($vendorFrameworkDir, 0755, true);
        $this->copyDir($frameworkRoot . 'cli', $vendorFrameworkDir . 'cli');
        copy($frameworkRoot . 'stone', $vendorFrameworkDir . 'stone');
        file_put_contents($fixture . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', "<?php\n// stub for test fixture\n");

        mkdir($fixture . 'src/App/Database/Functions', 0755, true);

        return $fixture;
    }

    /** @return array{0: int, 1: string} [exitCode, combined output] */
    private function runValidator(string $fixture): array
    {
        $stoneBinary = $fixture . 'vendor/progalaxyelabs/stonescriptphp/stone';
        $cmd = 'cd ' . escapeshellarg(rtrim($fixture, DIRECTORY_SEPARATOR))
            . ' && php ' . escapeshellarg($stoneBinary)
            . ' validate schema-contract'
            . ' --host=' . escapeshellarg((string) $this->host)
            . ' --port=' . escapeshellarg($this->port)
            . ' --dbname=' . escapeshellarg((string) $this->dbName)
            . ' --user=' . escapeshellarg($this->user)
            . ' --password=' . escapeshellarg($this->password)
            . ' 2>&1';

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    /**
     * The DTO/DB nullability-drift bug class this command exists to catch:
     * a legacy scalar-argument wrapper (no {Class}Params object at all) for
     * a live SQL function where most parameters are DEFAULT'd/optional. The
     * harness must FAIL with a precise, actionable message naming both the
     * function and the drift.
     */
    public function test_legacy_scalar_wrapper_fails_against_live_function_with_defaults(): void
    {
        $this->createSqlFunction(<<<'SQL'
            CREATE OR REPLACE FUNCTION create_widget_order(
                p_customer_id INTEGER,
                p_vendor_id   INTEGER,
                p_contract_id INTEGER DEFAULT NULL,
                p_currency    CHAR(3) DEFAULT 'INR',
                p_notes       TEXT    DEFAULT ''
            )
            RETURNS TABLE (id INTEGER, order_number VARCHAR)
            AS $$ BEGIN END; $$ LANGUAGE plpgsql;
        SQL);

        $fixture = $this->buildFixture();
        file_put_contents(
            $fixture . 'src/App/Database/Functions/FnCreateWidgetOrder.php',
            <<<'PHP'
            <?php
            namespace App\Database\Functions;
            use StoneScriptPHP\Database;
            class CreateWidgetOrderModel { public int $id; public string $order_number; }
            class FnCreateWidgetOrder
            {
                public static function run(int $p_customer_id, int $p_vendor_id, int $p_contract_id, string $p_currency, string $p_notes): array
                {
                    $function_name = 'create_widget_order';
                    $rows = Database::fn($function_name, [$p_customer_id, $p_vendor_id, $p_contract_id, $p_currency, $p_notes]);
                    return Database::result_as_table($function_name, $rows, CreateWidgetOrderModel::class);
                }
            }
            PHP
        );

        [$exitCode, $output] = $this->runValidator($fixture);

        $this->assertNotSame(0, $exitCode, "expected drift to be caught, but validator exited 0:\n$output");
        $this->assertStringContainsString('create_widget_order', $output);
        $this->assertStringContainsString('LEGACY WRAPPER DRIFT', $output);
        $this->assertStringContainsString('optional/DEFAULT', $output);
    }

    /**
     * A correctly-shaped {Class}Params DTO (properties nullable exactly where
     * the live SQL function has DEFAULTs) must PASS.
     */
    public function test_correct_params_object_passes_against_live_function(): void
    {
        $this->createSqlFunction(<<<'SQL'
            CREATE OR REPLACE FUNCTION create_widget_order(
                p_customer_id INTEGER,
                p_vendor_id   INTEGER,
                p_contract_id INTEGER DEFAULT NULL,
                p_currency    CHAR(3) DEFAULT 'INR',
                p_notes       TEXT    DEFAULT ''
            )
            RETURNS TABLE (id INTEGER, order_number VARCHAR)
            AS $$ BEGIN END; $$ LANGUAGE plpgsql;
        SQL);

        $fixture = $this->buildFixture();
        file_put_contents(
            $fixture . 'src/App/Database/Functions/FnCreateWidgetOrder.php',
            <<<'PHP'
            <?php
            namespace App\Database\Functions;
            use StoneScriptPHP\Database;
            use StoneScriptPHP\Binding\TypedArray;
            class CreateWidgetOrderModel { public int $id; public string $order_number; }
            class CreateWidgetOrderParams
            {
                public int $p_customer_id;
                public int $p_vendor_id;
                public ?int $p_contract_id = null;
                public ?string $p_currency = null;
                public ?string $p_notes = null;
            }
            class FnCreateWidgetOrder
            {
                public static function run(CreateWidgetOrderParams $params): TypedArray
                {
                    $function_name = 'create_widget_order';
                    $rows = Database::fnTyped($function_name, $params);
                    return Database::result_as_typed_table($function_name, $rows, CreateWidgetOrderModel::class);
                }
            }
            PHP
        );

        [$exitCode, $output] = $this->runValidator($fixture);

        $this->assertSame(0, $exitCode, "expected the correctly-typed DTO to pass, but validator failed:\n$output");
        $this->assertStringContainsString('PASS', $output);
    }

    private function copyDir(string $src, string $dst): void
    {
        mkdir($dst, 0755, true);
        foreach (scandir($src) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $srcPath = $src . DIRECTORY_SEPARATOR . $item;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $item;
            if (is_dir($srcPath)) {
                $this->copyDir($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->deleteDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
