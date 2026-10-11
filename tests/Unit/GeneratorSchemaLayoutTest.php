<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CliProjectHarness.php';
require_once dirname(__DIR__, 2) . '/cli/helpers/schema-layout.php';

/** Generators write SQL where the gateway commands read it: src/postgresql/main/postgresql/. */
final class GeneratorSchemaLayoutTest extends TestCase
{
    private CliProjectHarness $project;

    protected function setUp(): void
    {
        $this->project = new CliProjectHarness();
        $this->project->write('.env', "DB_GATEWAY_URL=http://127.0.0.1:9\nDB_GATEWAY_PLATFORM=p\nDB_GATEWAY_SCHEMA_NAME=main\n");
        mkdir($this->project->dir . '/src/App', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_layout_helper_prefers_nested_and_keeps_an_existing_flat_project_flat(): void
    {
        $src = $this->project->dir . '/src/';
        $this->assertTrue(sspUseNestedMainLayout($src), 'fresh project');

        $this->project->write('src/postgresql/functions/f.pgsql', 'select 1;');
        $this->assertFalse(sspUseNestedMainLayout($src), 'existing flat project');

        mkdir($src . 'postgresql/main/postgresql', 0700, true);
        $this->assertTrue(sspUseNestedMainLayout($src), 'nested wins when present');
    }

    public function test_generate_auth_writes_sql_where_the_gateway_reads_it(): void
    {
        [$code, $out] = $this->project->stone(["generate", "auth:email-password"]);
        $nested = $this->project->dir . '/src/postgresql/main/postgresql/migrations';
        $this->assertFileExists($nested . "/001_create_users_table.sql", "exit $code: $out");
        $this->assertFileDoesNotExist($this->project->dir . '/migrations/001_create_users_table.sql');
    }

    public function test_generate_invitations_writes_sql_where_the_gateway_reads_it(): void
    {
        $this->project->stone(['generate', 'invitations']);
        $base = $this->project->dir . '/src/postgresql/main/postgresql';
        $this->assertFileExists($base . '/functions/create_platform_invitation.pgsql');
        $this->assertNotEmpty(glob($base . '/migrations/*create_platform_invitations*'));
        $this->assertFileDoesNotExist($this->project->dir . '/src/postgresql/functions/create_platform_invitation.pgsql');
        $this->assertDirectoryDoesNotExist($this->project->dir . '/migrations');
    }

    public function test_a_project_already_on_the_flat_layout_keeps_it(): void
    {
        $this->project->write('src/postgresql/tables/t.pgsql', 'create table t (id int);');
        $this->project->stone(['generate', 'invitations']);
        $this->assertFileExists($this->project->dir . '/src/postgresql/functions/create_platform_invitation.pgsql');
        $this->assertDirectoryDoesNotExist($this->project->dir . '/src/postgresql/main');
    }

    public function test_generate_model_prefers_the_nested_main_copy_in_a_mixed_layout(): void
    {
        $fn = fn(string $name) => "CREATE OR REPLACE FUNCTION $name(p_id INT)\nRETURNS TABLE(id INT) AS $$\nBEGIN\nRETURN QUERY SELECT p_id;\nEND;\n$$ LANGUAGE plpgsql;\n";
        $this->project->write('src/postgresql/functions/probe.pgsql', $fn('flat_probe'));
        $this->project->write('src/postgresql/main/postgresql/functions/probe.pgsql', $fn('nested_probe'));
        [$code, $out] = $this->project->stone(['generate', 'model', 'probe.pgsql']);
        $this->assertSame(0, $code, $out);
        $this->assertFileExists($this->project->dir . '/src/App/Database/Functions/FnNestedProbe.php');
        $this->assertFileDoesNotExist($this->project->dir . '/src/App/Database/Functions/FnFlatProbe.php');
    }
}
