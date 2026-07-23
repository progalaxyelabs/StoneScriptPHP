<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Real, end-to-end test of `php stone generate tenant-governance`
 * (cli/generate-tenant-governance.php). Same approach as
 * GenerateInvitationsCommandTest: build a minimal, REAL (non-symlinked)
 * vendor/progalaxyelabs/stonescriptphp install in a temp dir and run the
 * actual `stone` dispatcher against it.
 *
 * @covers cli/generate-tenant-governance.php
 */
class GenerateTenantGovernanceCommandTest extends TestCase
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

    /**
     * @param bool $nested  Seed the nested main-DB layout
     *   (src/postgresql/main/postgresql/*) a real fleet platform uses, plus a
     *   prior numbered migration so sequential numbering is exercised. When
     *   false, no schema dirs exist at all → flat layout + timestamp naming.
     */
    private function buildFixture(bool $nested): string
    {
        $frameworkRoot = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR;

        $fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssp_tg_fixture_' . uniqid('', true) . DIRECTORY_SEPARATOR;
        $this->fixtures[] = $fixture;

        $vendorFrameworkDir = $fixture . 'vendor' . DIRECTORY_SEPARATOR . 'progalaxyelabs' . DIRECTORY_SEPARATOR . 'stonescriptphp' . DIRECTORY_SEPARATOR;
        mkdir($vendorFrameworkDir, 0755, true);

        $this->copyDir($frameworkRoot . 'cli', $vendorFrameworkDir . 'cli');
        mkdir($vendorFrameworkDir . 'src' . DIRECTORY_SEPARATOR . 'Templates', 0755, true);
        $this->copyDir(
            $frameworkRoot . 'src/Templates/TenantGovernance',
            $vendorFrameworkDir . 'src/Templates/TenantGovernance'
        );
        copy($frameworkRoot . 'stone', $vendorFrameworkDir . 'stone');

        // `stone` requires vendor/autoload.php; a stub suffices — neither the
        // generator nor generate-model.php reference a StoneScriptPHP\* class
        // at runtime (both pure procedural file/string handling).
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

    private function runGenerator(string $fixture): array
    {
        $stoneBinary = $fixture . 'vendor/progalaxyelabs/stonescriptphp/stone';
        $cmd = 'cd ' . escapeshellarg(rtrim($fixture, DIRECTORY_SEPARATOR))
            . ' && php ' . escapeshellarg($stoneBinary) . ' generate tenant-governance 2>&1';
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        return [$exitCode, $output];
    }

    /** The 12 public functions that MUST each get a model wrapper. */
    private const PUBLIC_FUNCTIONS = [
        'create_tenant_membership', 'add_member', 'promote_to_admin', 'demote_admin',
        'promote_to_owner', 'demote_owner', 'set_job_role', 'set_membership_status',
        'remove_member', 'get_tenant_memberships', 'get_identity_tenant_memberships',
        'resolve_role_id',
    ];

    /** The 2 internal helpers that must NOT get a model wrapper. */
    private const INTERNAL_FUNCTIONS = [
        '_tenant_memberships_protect_creator', '_tenant_membership_tier',
    ];

    public function test_nested_layout_generates_all_files_into_main_db_dirs(): void
    {
        $fixture = $this->buildFixture(nested: true);

        [$exitCode, $output] = $this->runGenerator($fixture);
        $outputText = implode("\n", $output);

        $this->assertSame(0, $exitCode, "generator exited non-zero:\n$outputText");
        $this->assertStringContainsString('Detected nested main-DB layout', $outputText);
        $this->assertStringContainsString('Tenant governance scaffolding complete', $outputText);

        $mainBase = $fixture . 'src/postgresql/main/postgresql/';

        // Declarative table.
        $this->assertFileExists($mainBase . 'tables/tenant_memberships.pgsql');

        // Migration — sequential numbering continued from 037 → 038.
        $migration = $mainBase . 'migrations/038_create_tenant_memberships.pgsql';
        $this->assertFileExists($migration);
        $migrationSql = file_get_contents($migration);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS tenant_memberships', $migrationSql);
        $this->assertStringContainsString('CREATE TRIGGER trg_protect_tenant_creator', $migrationSql);
        // Mandatory destructive-DDL self-check (system prompt §1c/§3.3.3).
        // DROP TRIGGER on a just-declared trigger is not destructive to data;
        // assert no table/schema/database drops or truncates specifically.
        $this->assertDoesNotMatchRegularExpression(
            '/DROP\s+TABLE|TRUNCATE|DROP\s+DATABASE|DROP\s+SCHEMA/i',
            $migrationSql
        );

        // All 14 SQL functions present.
        foreach (array_merge(self::PUBLIC_FUNCTIONS, self::INTERNAL_FUNCTIONS) as $fn) {
            $this->assertFileExists($mainBase . 'functions/' . $fn . '.pgsql', "missing function $fn");
        }

        // Model wrappers — exactly the 12 public functions, valid syntax.
        foreach (self::PUBLIC_FUNCTIONS as $fn) {
            $wrapper = $fixture . 'src/App/Database/Functions/Fn' . $this->studly($fn) . '.php';
            $this->assertFileExists($wrapper, "missing model wrapper for $fn");
            $this->assertPhpSyntaxValid($wrapper);
        }

        // Internal helpers must NOT get model wrappers.
        foreach (self::INTERNAL_FUNCTIONS as $fn) {
            $wrapper = $fixture . 'src/App/Database/Functions/Fn' . $this->studly(ltrim($fn, '_')) . '.php';
            $this->assertFileDoesNotExist($wrapper, "internal helper $fn must not get a model wrapper");
        }

        // Config hook.
        $config = $fixture . 'config/tenant-governance.php';
        $this->assertFileExists($config);
        $this->assertPhpSyntaxValid($config);
    }

    public function test_flat_layout_falls_back_to_timestamp_migration(): void
    {
        $fixture = $this->buildFixture(nested: false);

        [$exitCode, $output] = $this->runGenerator($fixture);
        $outputText = implode("\n", $output);

        $this->assertSame(0, $exitCode, "generator exited non-zero:\n$outputText");
        $this->assertStringContainsString('Using flat layout', $outputText);

        $this->assertFileExists($fixture . 'src/postgresql/tables/tenant_memberships.pgsql');
        $this->assertFileExists($fixture . 'src/postgresql/functions/resolve_role_id.pgsql');

        $migrationFiles = array_values(array_filter(
            scandir($fixture . 'migrations') ?: [],
            fn($f) => str_contains($f, 'create_tenant_memberships')
        ));
        $this->assertCount(1, $migrationFiles);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_create_tenant_memberships\.sql$/',
            $migrationFiles[0]
        );
    }

    public function test_is_idempotent_on_a_second_run(): void
    {
        $fixture = $this->buildFixture(nested: true);

        [$exitCode1] = $this->runGenerator($fixture);
        $this->assertSame(0, $exitCode1);

        [$exitCode2, $output2] = $this->runGenerator($fixture);
        $this->assertSame(0, $exitCode2);
        $this->assertStringContainsString('Skipped (already exists)', implode("\n", $output2));

        // Exactly one governance migration after two runs — not two.
        $migrationCount = 0;
        foreach (scandir($fixture . 'src/postgresql/main/postgresql/migrations') ?: [] as $entry) {
            if (str_contains($entry, 'create_tenant_memberships')) {
                $migrationCount++;
            }
        }
        $this->assertSame(1, $migrationCount, 'a second run must not create a duplicate migration');
    }

    private function studly(string $snake): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $snake)));
    }

    private function assertPhpSyntaxValid(string $file): void
    {
        $output = [];
        $returnVar = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $returnVar);
        $this->assertSame(0, $returnVar, "php -l failed for $file:\n" . implode("\n", $output));
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
