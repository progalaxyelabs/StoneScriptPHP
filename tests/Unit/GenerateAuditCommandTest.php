<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Real, end-to-end test of `php stone generate audit`
 * (cli/generate-audit.php). Same approach as
 * GenerateTenantGovernanceCommandTest: build a minimal, REAL (non-symlinked)
 * vendor/progalaxyelabs/stonescriptphp install in a temp dir and run the
 * actual `stone` dispatcher against it.
 *
 * @covers cli/generate-audit.php
 */
class GenerateAuditCommandTest extends TestCase
{
    /** @var string[] */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $this->deleteDir($fixture);
        }
        $this->fixtures = [];
    }

    private function buildFixture(bool $nested): string
    {
        $frameworkRoot = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR;

        $fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssp_audit_fixture_' . uniqid('', true) . DIRECTORY_SEPARATOR;
        $this->fixtures[] = $fixture;

        $vendorFrameworkDir = $fixture . 'vendor' . DIRECTORY_SEPARATOR . 'progalaxyelabs' . DIRECTORY_SEPARATOR . 'stonescriptphp' . DIRECTORY_SEPARATOR;
        mkdir($vendorFrameworkDir, 0755, true);

        $this->copyDir($frameworkRoot . 'cli', $vendorFrameworkDir . 'cli');
        mkdir($vendorFrameworkDir . 'src' . DIRECTORY_SEPARATOR . 'Templates', 0755, true);
        $this->copyDir(
            $frameworkRoot . 'src/Templates/Audit',
            $vendorFrameworkDir . 'src/Templates/Audit'
        );
        copy($frameworkRoot . 'stone', $vendorFrameworkDir . 'stone');

        file_put_contents($fixture . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', "<?php\n// stub\n");

        if ($nested) {
            $mainBase = $fixture . 'src/postgresql/main/postgresql/';
            mkdir($mainBase . 'tables', 0755, true);
            mkdir($mainBase . 'functions', 0755, true);
            mkdir($mainBase . 'migrations', 0755, true);
            file_put_contents($mainBase . 'migrations/037_prior.pgsql', "-- seed\n");
        }

        return $fixture;
    }

    private function runGenerator(string $fixture, string $extraArgs = ''): array
    {
        $stoneBinary = $fixture . 'vendor/progalaxyelabs/stonescriptphp/stone';
        $cmd = 'cd ' . escapeshellarg(rtrim($fixture, DIRECTORY_SEPARATOR))
            . ' && php ' . escapeshellarg($stoneBinary) . ' generate audit ' . $extraArgs . ' 2>&1';
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        return [$exitCode, $output];
    }

    public function test_default_tables_generates_table_function_and_migration(): void
    {
        $fixture = $this->buildFixture(nested: true);

        [$exitCode, $output] = $this->runGenerator($fixture);
        $outputText = implode("\n", $output);

        $this->assertSame(0, $exitCode, "generator exited non-zero:\n$outputText");
        $this->assertStringContainsString('Audit scaffolding complete', $outputText);

        $mainBase = $fixture . 'src/postgresql/main/postgresql/';

        $this->assertFileExists($mainBase . 'tables/_audit_log.pgsql');
        $this->assertFileExists($mainBase . 'functions/_audit_capture_row.pgsql');
        $this->assertFileExists($mainBase . 'functions/_audit_capture_truncate.pgsql');

        // TRUNCATE is captured too (AFTER-ROW triggers never fire on TRUNCATE).
        $tableSql = file_get_contents($mainBase . 'tables/_audit_log.pgsql');
        $this->assertStringContainsString("'TRUNCATE'", $tableSql);

        $migration = $mainBase . 'migrations/038_create_audit_log.pgsql';
        $this->assertFileExists($migration);
        $sql = file_get_contents($migration);

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS _audit_log', $sql);
        $this->assertStringContainsString("REVOKE UPDATE, DELETE, TRUNCATE ON _audit_log", $sql);
        // Default table set, each guarded by to_regclass so partial rollout
        // (a table that doesn't exist yet on this DB) doesn't fail the migration
        // -- non-silently: RAISE WARNING, not a quiet skip.
        foreach (['identities', 'tenants', 'tenant_memberships'] as $table) {
            $this->assertStringContainsString("to_regclass('$table')", $sql);
            $this->assertStringContainsString("trg_audit_$table", $sql);
            $this->assertStringContainsString("trg_audit_truncate_$table", $sql);
            $this->assertStringContainsString("REVOKE TRUNCATE ON $table FROM", $sql);
        }
        $this->assertStringContainsString('RAISE WARNING', $sql);

        // Mandatory destructive-DDL self-check (system prompt §7).
        $this->assertDoesNotMatchRegularExpression(
            '/DROP\s+TABLE|TRUNCATE\s+_audit_log|DROP\s+DATABASE|DROP\s+SCHEMA/i',
            $sql
        );

        // No model wrapper for either internal trigger function.
        $this->assertFileDoesNotExist($fixture . 'src/App/Database/Functions/FnAuditCaptureRow.php');
        $this->assertFileDoesNotExist($fixture . 'src/App/Database/Functions/FnAuditCaptureTruncate.php');
    }

    public function test_custom_tables_flag_is_honored(): void
    {
        $fixture = $this->buildFixture(nested: true);

        [$exitCode, $output] = $this->runGenerator($fixture, '--tables=orders,customers');
        $outputText = implode("\n", $output);

        $this->assertSame(0, $exitCode, "generator exited non-zero:\n$outputText");

        $sql = file_get_contents($fixture . 'src/postgresql/main/postgresql/migrations/038_create_audit_log.pgsql');
        $this->assertStringContainsString("trg_audit_orders", $sql);
        $this->assertStringContainsString("trg_audit_customers", $sql);
        $this->assertStringNotContainsString("trg_audit_identities", $sql);
    }

    public function test_rejects_invalid_table_identifier(): void
    {
        $fixture = $this->buildFixture(nested: true);

        [$exitCode, $output] = $this->runGenerator($fixture, "--tables='bad; DROP TABLE x'");
        $this->assertNotSame(0, $exitCode, "generator should refuse a non-identifier table name:\n" . implode("\n", $output));
    }

    public function test_is_idempotent_on_a_second_run(): void
    {
        $fixture = $this->buildFixture(nested: true);

        [$exitCode1] = $this->runGenerator($fixture);
        $this->assertSame(0, $exitCode1);

        [$exitCode2, $output2] = $this->runGenerator($fixture);
        $this->assertSame(0, $exitCode2);
        $this->assertStringContainsString('Skipped (already exists)', implode("\n", $output2));

        $migrationCount = 0;
        foreach (scandir($fixture . 'src/postgresql/main/postgresql/migrations') ?: [] as $entry) {
            if (str_contains($entry, 'create_audit_log')) {
                $migrationCount++;
            }
        }
        $this->assertSame(1, $migrationCount, 'a second run must not create a duplicate migration');
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
