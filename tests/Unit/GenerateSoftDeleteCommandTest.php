<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Real, end-to-end test of `php stone generate soft-delete`
 * (cli/generate-soft-delete.php). Same approach as
 * GenerateTenantGovernanceCommandTest / GenerateAuditCommandTest.
 *
 * @covers cli/generate-soft-delete.php
 */
class GenerateSoftDeleteCommandTest extends TestCase
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

        $fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssp_sd_fixture_' . uniqid('', true) . DIRECTORY_SEPARATOR;
        $this->fixtures[] = $fixture;

        $vendorFrameworkDir = $fixture . 'vendor' . DIRECTORY_SEPARATOR . 'progalaxyelabs' . DIRECTORY_SEPARATOR . 'stonescriptphp' . DIRECTORY_SEPARATOR;
        mkdir($vendorFrameworkDir, 0755, true);

        $this->copyDir($frameworkRoot . 'cli', $vendorFrameworkDir . 'cli');
        mkdir($vendorFrameworkDir . 'src' . DIRECTORY_SEPARATOR . 'Templates', 0755, true);
        $this->copyDir(
            $frameworkRoot . 'src/Templates/SoftDelete',
            $vendorFrameworkDir . 'src/Templates/SoftDelete'
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
            . ' && php ' . escapeshellarg($stoneBinary) . ' generate soft-delete ' . $extraArgs . ' 2>&1';
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        return [$exitCode, $output];
    }

    public function test_default_tables_generate_cascade_variant_for_identities(): void
    {
        $fixture = $this->buildFixture(nested: true);

        [$exitCode, $output] = $this->runGenerator($fixture);
        $outputText = implode("\n", $output);

        $this->assertSame(0, $exitCode, "generator exited non-zero:\n$outputText");
        $this->assertStringContainsString('cascade variant', $outputText);
        $this->assertStringContainsString('Soft-delete scaffolding complete', $outputText);

        $mainBase = $fixture . 'src/postgresql/main/postgresql/';

        $this->assertFileExists($mainBase . 'tables/_deletion_archive.pgsql');

        // Cascade variant for identities.
        $identitiesFn = file_get_contents($mainBase . 'functions/request_identities_deletion.pgsql');
        $this->assertStringContainsString('tenant_memberships.is_tenant_creator', $identitiesFn);
        $this->assertStringContainsString("to_regclass('tenant_memberships')", $identitiesFn);
        $this->assertStringContainsString('SECURITY DEFINER', $identitiesFn);
        // "Who" on deletion (set_config), and defensive against a
        // tenant_memberships schema that doesn't have status/updated_at.
        $this->assertStringContainsString("set_config('app.actor_id'", $identitiesFn);
        $this->assertStringContainsString('EXCEPTION WHEN undefined_column', $identitiesFn);

        $tenantsFn = file_get_contents($mainBase . 'functions/request_tenants_deletion.pgsql');
        $this->assertStringContainsString('CREATE OR REPLACE FUNCTION request_tenants_deletion', $tenantsFn);
        $this->assertStringContainsString("set_config('app.actor_id'", $tenantsFn);

        $this->assertFileExists($mainBase . 'functions/support_restore_identities_deletion.pgsql');
        $this->assertFileExists($mainBase . 'functions/support_restore_tenants_deletion.pgsql');
        $this->assertFileExists($mainBase . 'functions/purge_expired_deletions.pgsql');
        $this->assertFileExists($mainBase . 'functions/is_email_blocked.pgsql');

        // is_email_blocked: live row authoritative (restore-is-dead fix),
        // hardened with SECURITY DEFINER + search_path.
        $emailBlockedFn = file_get_contents($mainBase . 'functions/is_email_blocked.pgsql');
        $this->assertStringContainsString('v_live_found', $emailBlockedFn);
        $this->assertStringContainsString('purged = true', $emailBlockedFn);
        $this->assertStringContainsString('SECURITY DEFINER', $emailBlockedFn);
        $this->assertStringContainsString('SET search_path', $emailBlockedFn);

        // support_restore: search_path pinned too, for consistency.
        $restoreFn = file_get_contents($mainBase . 'functions/support_restore_identities_deletion.pgsql');
        $this->assertStringContainsString('SET search_path', $restoreFn);

        // purge_expired_deletions: per-table exception handling + o_error +
        // archive purge keyed on actual deleted subject_ids, not a blanket filter.
        $purgeFn = file_get_contents($mainBase . 'functions/purge_expired_deletions.pgsql');
        $this->assertStringContainsString('o_error TEXT', $purgeFn);
        $this->assertStringContainsString('EXCEPTION WHEN OTHERS', $purgeFn);
        // Keyed on (subject_id, deleted_at) pairs, not subject_id alone —
        // see _purge_table_block.pgsql.template's comment for why.
        $this->assertStringContainsString('unnest(v_deleted_ids, v_deleted_ats)', $purgeFn);
        $this->assertStringContainsString('a.deleted_at = d.deleted_at', $purgeFn);
        // Known, documented limitation: tenant-governance's creator-protection
        // trigger currently blocks purging a tenant with an active founder
        // membership row — WHEN OTHERS (not just foreign_key_violation)
        // catches that custom exception too, isolated to the tenants block.
        $this->assertStringContainsString('WHEN OTHERS', $purgeFn);
        $this->assertStringContainsString('trg_protect_tenant_creator', $purgeFn);

        $migration = $mainBase . 'migrations/038_create_soft_delete.pgsql';
        $this->assertFileExists($migration);
        $sql = file_get_contents($migration);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS _deletion_archive', $sql);
        $this->assertStringContainsString('REVOKE UPDATE, DELETE, TRUNCATE ON _deletion_archive', $sql);
        $this->assertStringContainsString('GRANT UPDATE (purged, purged_at) ON _deletion_archive', $sql);
        $this->assertStringContainsString('RAISE WARNING', $sql);
        foreach (['identities', 'tenants'] as $table) {
            $this->assertStringContainsString("ALTER TABLE $table", $sql);
            $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS deleted_at', $sql);
        }

        // Declarative table files patched (fix for schema-sync drift) —
        // this fixture has no pre-existing identities.pgsql/tenants.pgsql,
        // so the generator must report 'not_found' cleanly, not crash.
        $this->assertStringContainsString('No declarative file', $outputText);

        // Mandatory destructive-DDL self-check (system prompt §7) — additive
        // ALTER TABLE ADD COLUMN is fine; assert no drops/truncate-statements/
        // type changes. The word TRUNCATE legitimately appears inside the
        // REVOKE ... TRUNCATE ... privilege list above (revoking the
        // privilege, not issuing the statement) — only flag an actual
        // `TRUNCATE <table>` statement (TRUNCATE as the first token of a
        // statement, i.e. preceded by `;`/start-of-string/whitespace-only).
        $this->assertDoesNotMatchRegularExpression(
            '/DROP\s+TABLE|DROP\s+DATABASE|DROP\s+SCHEMA|ALTER\s+COLUMN\s+\w+\s+TYPE|(?:;|^)\s*TRUNCATE\s+\w/im',
            $sql
        );

        // Model wrappers for every generated public function.
        foreach ([
            'FnRequestIdentitiesDeletion', 'FnRequestTenantsDeletion',
            'FnSupportRestoreIdentitiesDeletion', 'FnSupportRestoreTenantsDeletion',
            'FnPurgeExpiredDeletions', 'FnIsEmailBlocked',
        ] as $class) {
            $wrapper = $fixture . 'src/App/Database/Functions/' . $class . '.php';
            $this->assertFileExists($wrapper, "missing model wrapper $class");
            $this->assertPhpSyntaxValid($wrapper);
        }
    }

    public function test_patches_existing_declarative_table_file(): void
    {
        $fixture = $this->buildFixture(nested: true);
        $mainBase = $fixture . 'src/postgresql/main/postgresql/';

        $identitiesTable = $mainBase . 'tables/identities.pgsql';
        file_put_contents($identitiesTable, <<<SQL
        CREATE TABLE IF NOT EXISTS identities (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            email TEXT UNIQUE NOT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        );

        CREATE INDEX IF NOT EXISTS idx_identities_email ON identities(email);
        SQL);

        [$exitCode, $output] = $this->runGenerator($fixture, '--tables=identities:id');
        $outputText = implode("\n", $output);
        $this->assertSame(0, $exitCode, $outputText);
        $this->assertStringContainsString('Patched', $outputText);

        $patched = file_get_contents($identitiesTable);
        $this->assertStringContainsString('deleted_at TIMESTAMPTZ', $patched);
        $this->assertStringContainsString('purge_after TIMESTAMPTZ', $patched);
        $this->assertStringContainsString('delete_requested_by TEXT', $patched);
        // The trailing CREATE INDEX (unrelated to the CREATE TABLE block)
        // must survive untouched, proving the patch found the CORRECT
        // closing paren rather than some later one.
        $this->assertStringContainsString('CREATE INDEX IF NOT EXISTS idx_identities_email', $patched);

        // Idempotent — a second run must not double-patch.
        [$exitCode2, $output2] = $this->runGenerator($fixture, '--tables=identities:id');
        $this->assertSame(0, $exitCode2);
        $this->assertStringContainsString('already has deleted_at', implode("\n", $output2));
        $this->assertSame(1, substr_count(file_get_contents($identitiesTable), 'deleted_at TIMESTAMPTZ'));
    }

    public function test_no_cascade_when_tenants_not_configured(): void
    {
        $fixture = $this->buildFixture(nested: true);

        [$exitCode, $output] = $this->runGenerator($fixture, '--tables=identities:id');
        $this->assertSame(0, $exitCode, implode("\n", $output));

        $mainBase = $fixture . 'src/postgresql/main/postgresql/';
        $identitiesFn = file_get_contents($mainBase . 'functions/request_identities_deletion.pgsql');
        $this->assertStringNotContainsString('tenant_memberships.is_tenant_creator', $identitiesFn);
        // Still the email-capture variant (identities is the configured email table).
        $this->assertStringContainsString('RETURNING email INTO v_email', $identitiesFn);
        $this->assertFileDoesNotExist($mainBase . 'functions/request_tenants_deletion.pgsql');
    }

    public function test_no_email_blocked_fn_when_no_identities_table(): void
    {
        $fixture = $this->buildFixture(nested: true);

        [$exitCode, $output] = $this->runGenerator($fixture, '--tables=tenants:uuid');
        $this->assertSame(0, $exitCode, implode("\n", $output));

        $mainBase = $fixture . 'src/postgresql/main/postgresql/';
        $this->assertFileDoesNotExist($mainBase . 'functions/is_email_blocked.pgsql');
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
            if (str_contains($entry, 'create_soft_delete')) {
                $migrationCount++;
            }
        }
        $this->assertSame(1, $migrationCount, 'a second run must not create a duplicate migration');
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
