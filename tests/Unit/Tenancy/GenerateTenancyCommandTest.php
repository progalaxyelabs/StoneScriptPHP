<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Tests\Unit\CliProjectHarness;

/**
 * `php stone generate tenancy` and `php stone platform:register` through the real `stone` binary,
 * plus static checks on the SQL templates (vocabulary, no destructive DDL, plpgsql functions).
 *
 * @covers cli/generate-tenancy.php
 * @covers cli/platform-register.php
 */
final class GenerateTenancyCommandTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../../src/Templates/Tenancy';

    private CliProjectHarness $project;

    protected function setUp(): void
    {
        $this->project = new CliProjectHarness();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_generates_main_and_tenant_files_in_the_nested_layout(): void
    {
        [$status, $out] = $this->project->stone(['generate', 'tenancy']);

        $this->assertSame(0, $status, $out);
        $main = $this->project->dir . '/src/postgresql/main/postgresql';
        $tenant = $this->project->dir . '/src/postgresql/tenant/postgresql';
        foreach (['platform', 'tenants', 'tenant_memberships'] as $table) {
            $this->assertFileExists("$main/tables/$table.pgsql");
        }
        foreach (['platform_get', 'platform_upsert', 'tenancy_get_tenant_status', 'tenancy_upsert_tenant', 'tenancy_set_tenant_status', 'tenancy_create_membership', 'tenancy_get_identity_memberships'] as $fn) {
            $this->assertFileExists("$main/functions/$fn.pgsql");
        }
        foreach (['users', 'user_roles', 'user_role_privileges'] as $table) {
            $this->assertFileExists("$tenant/tables/$table.pgsql");
        }
        foreach (['tenant_user_upsert', 'tenant_user_assign_role', 'tenant_get_identity_role_privileges'] as $fn) {
            $this->assertFileExists("$tenant/functions/$fn.pgsql");
        }
        $this->assertFileExists("$tenant/functions/tenant_seed_privileges.pgsql");
        $this->assertFileExists("$main/functions/tenancy_find_membership.pgsql");
        $this->assertFileExists("$main/functions/tenancy_set_membership_status.pgsql");
        $this->assertFileExists("$main/functions/tenancy_list_tenants.pgsql");
        $this->assertFileExists("$main/functions/tenancy_mirror_membership_status.pgsql");
        $this->assertFileExists("$main/functions/tenancy_mirror_drift.pgsql");
        $this->assertFileExists("$main/functions/tenancy_list_active_member_identities.pgsql");
        $this->assertFileExists("$main/tables/tenancy_mirror_drift.pgsql");
        $this->assertFileExists("$tenant/functions/tenant_identities_with_access.pgsql");
        $this->assertFileExists("$tenant/functions/tenant_missing_privileges.pgsql");
        $this->assertFileExists("$tenant/functions/tenant_role_privilege_counts.pgsql");
        $this->assertFileExists($this->project->dir . '/config/tenant-privileges.php', 'the platform-wide privilege seed stub');
        $this->assertNotEmpty(glob("$main/migrations/*tenancy_core.*"));
        $this->assertFileDoesNotExist("$main/tables/identities.pgsql", 'identities is only for --builtin');
        $this->assertFileDoesNotExist("$main/functions/tenancy_find_request.pgsql");
    }

    public function test_builtin_adds_identities_and_the_idempotency_ledger(): void
    {
        [$status, $out] = $this->project->stone(['generate', 'tenancy', '--builtin']);

        $this->assertSame(0, $status, $out);
        $main = $this->project->dir . '/src/postgresql/main/postgresql';
        $this->assertFileExists("$main/tables/identities.pgsql");
        $this->assertFileExists("$main/tables/tenant_provision_requests.pgsql");
        $this->assertFileExists("$main/functions/tenancy_find_request.pgsql");
        $this->assertFileExists("$main/functions/tenancy_save_request.pgsql");
    }

    public function test_rerun_changes_nothing_and_existing_declarations_are_kept_with_guidance(): void
    {
        $tables = $this->project->dir . '/src/postgresql/main/postgresql/tables';
        mkdir($tables, 0755, true);
        file_put_contents("$tables/tenants.pgsql", "-- my own tenants table\nCREATE TABLE IF NOT EXISTS tenants (uuid UUID PRIMARY KEY, address TEXT);\n");
        mkdir($this->project->dir . '/src/postgresql/main/postgresql/migrations', 0755, true);
        file_put_contents($this->project->dir . '/src/postgresql/main/postgresql/migrations/007_prior.pgsql', "-- prior\n");

        [$status, $first] = $this->project->stone(['generate', 'tenancy']);
        $this->assertSame(2, $status, $first . ' (exit 2: a table was refused)');
        $this->assertStringContainsString("Refused: table 'tenants' is already defined", $first);
        $this->assertStringContainsString('core columns', $first);
        $this->assertStringContainsString("-- my own tenants table", (string) file_get_contents("$tables/tenants.pgsql"));
        $this->assertFileExists($this->project->dir . '/src/postgresql/main/postgresql/migrations/008_tenancy_core.pgsql', 'continues the existing numbering');

        $before = $this->snapshot();
        [$status, $second] = $this->project->stone(['generate', 'tenancy']);
        $this->assertSame(2, $status, $second);
        $this->assertSame($before, $this->snapshot(), 'a second run must not change or add any file');
    }

    public function test_existing_tables_are_found_by_parsing_create_table_not_by_file_name(): void
    {
        $tables = $this->project->dir . '/src/postgresql/main/postgresql/tables';
        mkdir($tables, 0755, true);
        // Defined in a file with an unrelated name, with a schema prefix and quotes; a commented-out CREATE TABLE
        // for tenant_memberships must NOT count as a definition.
        file_put_contents("$tables/001_core_schema.pgsql", "-- CREATE TABLE tenant_memberships (id UUID);\n/* CREATE TABLE platform (x INT); */\nCREATE TABLE IF NOT EXISTS public.\"Tenants\" (uuid UUID PRIMARY KEY);\n");

        [$status, $out] = $this->project->stone(['generate', 'tenancy']);

        $this->assertSame(2, $status, $out);
        $this->assertStringContainsString("Refused: table 'tenants' is already defined in src/postgresql/main/postgresql/tables/001_core_schema.pgsql", $out);
        $this->assertFileDoesNotExist("$tables/tenants.pgsql", 'no second definition of tenants is written');
        $this->assertFileExists("$tables/tenant_memberships.pgsql", 'a commented-out definition is not a definition');
        $this->assertFileExists("$tables/platform.pgsql");
    }

    public function test_an_existing_tenant_users_table_gets_an_expand_migration_and_no_second_definition(): void
    {
        $tenantTables = $this->project->dir . '/src/postgresql/tenant/postgresql/tables';
        mkdir($tenantTables, 0755, true);
        file_put_contents("$tenantTables/accounts.pgsql", "CREATE TABLE users (id UUID PRIMARY KEY, email TEXT);\n");

        [$status, $out] = $this->project->stone(['generate', 'tenancy']);

        $this->assertSame(2, $status, $out);
        $this->assertStringContainsString("Refused: table 'users' is already defined", $out);
        $this->assertStringContainsString('exit status 2', $out, 'the guidance is printed first, then the refusal summary');
        $this->assertFileDoesNotExist("$tenantTables/users.pgsql");
        $this->assertNotEmpty(glob($this->project->dir . '/src/postgresql/tenant/postgresql/migrations/*users_expand_identity.*'));
        $this->assertFileExists("$tenantTables/user_roles.pgsql", 'the other tenant tables are still written');
    }

    public function test_the_users_expand_migration_is_forward_only_guarded_and_plpgsql(): void
    {
        $sql = (string) file_get_contents(self::TEMPLATES . '/tenant/migrations/users_expand_identity.pgsql.template');

        $this->assertStringContainsString('DO $$', $sql);
        $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS identity_id UUID', $sql);
        $this->assertStringContainsString("DEFAULT 'active'", $sql);
        $this->assertStringContainsString('UPDATE users SET identity_id = id WHERE identity_id IS NULL', $sql, 'backfilled from id');
        $this->assertStringContainsString('UNIQUE (identity_id)', $sql);
        $this->assertLessThan(strpos($sql, 'ADD CONSTRAINT users_identity_id_key'), strpos($sql, 'RAISE EXCEPTION'), 'the duplicate pre-check runs before the constraint');
        $this->assertMatchesRegularExpression('/RAISE EXCEPTION[^;]*duplicate users\.identity_id/', $sql);
        $this->assertStringContainsString('to_regclass', $sql, 'a database without the table is a no-op');
    }

    public function test_the_core_migration_maps_db_status_and_rederives_on_every_run(): void
    {
        $sql = (string) file_get_contents(self::TEMPLATES . '/main/migrations/tenancy_core.pgsql.template');
        $flat = preg_replace('/\s+/', ' ', $sql) ?? '';

        foreach (["WHEN 'creating' THEN 'provisioning'", "WHEN 'active' THEN 'provisioned'", "WHEN 'error' THEN 'provisioning_failed'", "WHEN 'suspended' THEN 'provisioned'", "WHEN 'deleted' THEN 'deprovisioned'"] as $mapping) {
            $this->assertStringContainsString($mapping, $flat);
        }
        $this->assertStringContainsString('WHERE tenant_provisioning_status IS NULL', $flat);
        $this->assertStringNotContainsString("IF NOT EXISTS ( SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'tenants' AND column_name = 'tenant_provisioning_status' ) THEN", $flat, 'the derivation is not inside a run-once guard');
        $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS tenant_provisioning_status', $flat);
    }

    public function test_the_invitations_template_reads_the_platform_code_through_env(): void
    {
        $tpl = (string) file_get_contents(__DIR__ . '/../../../src/Templates/Invitations/config/invitations.php.template');

        $this->assertStringContainsString('Env::get_instance()->PLATFORM_CODE', $tpl);
        $this->assertStringNotContainsString("getenv('PLATFORM_CODE')", $tpl);
    }

    public function test_a_platform_slug_longer_than_19_characters_stops_the_generator_before_writing(): void
    {
        $this->project->write('.env', "DB_GATEWAY_PLATFORM=" . str_repeat('a', 20) . "\n");

        [$status, $out] = $this->project->stone(['generate', 'tenancy']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('at most 19', $out);
        $this->assertDirectoryDoesNotExist($this->project->dir . '/src/postgresql');

        $this->project->write('.env', "DB_GATEWAY_PLATFORM=" . str_repeat('a', 19) . "\n");
        [$status, $out] = $this->project->stone(['generate', 'tenancy']);
        $this->assertSame(0, $status, $out);
    }

    public function test_the_platform_table_and_function_enforce_the_19_character_slug(): void
    {
        $table = (string) file_get_contents(self::TEMPLATES . '/main/tables/platform.pgsql.template');
        $fn = (string) file_get_contents(self::TEMPLATES . '/main/functions/platform_upsert.pgsql.template');

        $this->assertStringContainsString('length(platform_slug) <= 19', $table);
        $this->assertStringContainsString('char_length(p_platform_slug) > 19', $fn);
    }

    public function test_unknown_argument_is_an_error(): void
    {
        [$status, $out] = $this->project->stone(['generate', 'tenancy', '--nonsense']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('unknown argument', $out);
    }

    /** @return array<string,string> */
    private function snapshot(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->project->dir . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $files[$f->getPathname()] = md5((string) file_get_contents($f->getPathname()));
            }
        }
        ksort($files);
        return $files;
    }

    // ---- platform:register through the real binary ----

    public function test_platform_register_help_and_missing_arguments(): void
    {
        [$status, $out] = $this->project->stone(['platform:register', '--help']);
        $this->assertSame(0, $status, $out);
        $this->assertStringContainsString('--slug', $out);

        [$status, $out] = $this->project->stone(['platform:register', '--name', 'Acme', '--auth-url=http://auth.invalid:3139'], ['HOME' => $this->project->dir]);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('--slug', $out);
    }

    public function test_platform_register_ignores_an_operator_token_that_sits_in_the_application_env(): void
    {
        $this->project->write('.env', "STONE_OPERATOR_TOKEN=token-in-app-env-must-not-be-used\nAUTH_SERVICE_URL=http://auth.invalid:3139\n");

        [$status, $out] = $this->project->stone(
            ['platform:register', '--name', 'Acme', '--slug', 'acme', '--auth-url=http://auth.invalid:3139'],
            ['HOME' => $this->project->dir]
        );

        $this->assertSame(1, $status, $out);
        $this->assertStringContainsString('No operator token', $out);
        $this->assertStringNotContainsString('token-in-app-env-must-not-be-used', $out);
    }

    // ---- template checks ----

    /** @return list<string> */
    private function templates(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::TEMPLATES, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.template')) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    public function test_templates_contain_no_destructive_ddl(): void
    {
        foreach ($this->templates() as $file) {
            $sql = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/\bDROP\s+(TABLE|SCHEMA|DATABASE|COLUMN)\b|\bTRUNCATE\b|ALTER\s+COLUMN\s+\w+\s+(SET\s+DATA\s+)?TYPE/i', $sql, basename($file));
        }
        $this->assertNotEmpty($this->templates());
    }

    public function test_every_function_template_is_plpgsql_so_it_survives_the_gateway_deploy_order(): void
    {
        foreach ($this->templates() as $file) {
            if (!str_contains($file, '/functions/')) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            $this->assertStringContainsString('LANGUAGE plpgsql', $sql, basename($file));
            $this->assertDoesNotMatchRegularExpression('/LANGUAGE\s+sql/i', $sql, basename($file) . ' must not be LANGUAGE sql: it is checked at CREATE time, before the tables exist');
        }
    }

    public function test_status_vocabulary_is_exactly_the_five_values_everywhere_it_appears(): void
    {
        $vocabulary = "'provisioning', 'provisioned', 'provisioning_failed', 'deprovisioning', 'deprovisioned'";
        foreach (['main/tables/tenants', 'main/functions/tenancy_set_tenant_status'] as $name) {
            $this->assertStringContainsString($vocabulary, (string) file_get_contents(self::TEMPLATES . "/$name.pgsql.template"), $name);
        }
        $migration = (string) file_get_contents(self::TEMPLATES . '/main/migrations/tenancy_core.pgsql.template');
        $this->assertStringContainsString("'provisioning', 'provisioned', 'provisioning_failed', 'deprovisioning', 'deprovisioned'", preg_replace('/\s+/', ' ', $migration) ?? '');
        foreach ($this->templates() as $file) {
            $this->assertDoesNotMatchRegularExpression('/provisioning-failed|\bactivate\b/i', (string) file_get_contents($file), basename($file) . ': the words never mix');
        }
    }

    public function test_memberships_have_no_role_column_and_foreign_keys_do_not_cascade(): void
    {
        $table = (string) file_get_contents(self::TEMPLATES . '/main/tables/tenant_memberships.pgsql.template');
        $this->assertDoesNotMatchRegularExpression('/^\s*role\b/m', $table);
        $this->assertStringContainsString('ON DELETE NO ACTION', $table);
        $this->assertStringNotContainsString('CASCADE', $table);

        $migration = (string) file_get_contents(self::TEMPLATES . '/main/migrations/tenancy_core.pgsql.template');
        $this->assertStringContainsString('NOT VALID', $migration, 'existing orphans must not block the FK');
        $this->assertStringContainsString('ON DELETE NO ACTION', $migration);
    }

    public function test_migration_checks_the_data_before_it_tightens_a_constraint(): void
    {
        $migration = (string) file_get_contents(self::TEMPLATES . '/main/migrations/tenancy_core.pgsql.template');

        $this->assertMatchesRegularExpression('/RAISE EXCEPTION[^;]*duplicate tenants\.tenant_db_name/', $migration);
        $this->assertMatchesRegularExpression('/RAISE EXCEPTION[^;]*duplicate tenant_memberships/', $migration);
        $this->assertLessThan(strpos($migration, 'ADD CONSTRAINT tenants_tenant_db_name_key'), strpos($migration, 'RAISE EXCEPTION'), 'the guard runs before the constraint is added');
        $this->assertStringContainsString('IF NOT EXISTS', $migration);
    }

    public function test_tenant_tables_have_the_designed_uniqueness(): void
    {
        $users = (string) file_get_contents(self::TEMPLATES . '/tenant/tables/users.pgsql.template');
        $roles = (string) file_get_contents(self::TEMPLATES . '/tenant/tables/user_roles.pgsql.template');
        $privileges = (string) file_get_contents(self::TEMPLATES . '/tenant/tables/user_role_privileges.pgsql.template');

        $this->assertStringContainsString('UNIQUE (identity_id)', $users);
        $this->assertStringContainsString('UNIQUE (user_id, role_code)', $roles);
        $this->assertStringContainsString('UNIQUE (role_code, privilege_code)', $privileges);
        $this->assertStringNotContainsString('local_user_id', $users . $roles);
    }
}
