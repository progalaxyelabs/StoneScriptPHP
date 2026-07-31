<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Real, end-to-end test of `php stone generate invitations` (cli/generate-invitations.php).
 *
 * No existing PHPUnit test in this repo invokes a `generate` subcommand as a
 * real subprocess against a throwaway project (tests/Integration/test-code-generation.php
 * is the closest precedent, but it's a standalone script outside the
 * phpunit.xml testsuite, and it verifies template CONTENT rather than
 * actually running the generator). This test builds a minimal, real
 * `vendor/progalaxyelabs/stonescriptphp` install (a REAL COPY, not a
 * symlink — PHP resolves symlinks in __DIR__/__FILE__, which would break
 * both `stone`'s and the generator's own ROOT_PATH auto-detection, verified
 * directly during this task) and runs the actual `stone` CLI dispatcher
 * against it, exactly as a real platform would.
 *
 * @covers cli/generate-invitations.php
 */
class GenerateInvitationsCommandTest extends TestCase
{
    /** @var string[] Fixture directories created by this test, cleaned up in tearDown(). */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $this->deleteDir($fixture);
        }
        $this->fixtures = [];
    }

    /**
     * Builds a minimal, real (non-symlinked) `vendor/progalaxyelabs/stonescriptphp`
     * install inside a fresh temp directory, containing only what
     * `php stone generate invitations` actually needs to run: the `cli/`
     * scripts, `src/Templates/Invitations/`, and the `stone` binary itself.
     *
     * @param bool $withPriorMigration Seed migrations/001_create_users_table.sql
     *   (simulates a project that already ran `php stone generate auth:*`)
     *   so the sequential-numbering path is exercised.
     * @param bool $withRoutesPhp Seed a minimal flat v4.0 src/config/routes.php.
     */
    private function buildFixture(bool $withPriorMigration = true, bool $withRoutesPhp = true): string
    {
        $frameworkRoot = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR;

        $fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssp_invitations_fixture_' . uniqid('', true) . DIRECTORY_SEPARATOR;
        $this->fixtures[] = $fixture;

        $vendorFrameworkDir = $fixture . 'vendor' . DIRECTORY_SEPARATOR . 'progalaxyelabs' . DIRECTORY_SEPARATOR . 'stonescriptphp' . DIRECTORY_SEPARATOR;
        mkdir($vendorFrameworkDir, 0755, true);

        $this->copyDir($frameworkRoot . 'cli', $vendorFrameworkDir . 'cli');
        mkdir($vendorFrameworkDir . 'src' . DIRECTORY_SEPARATOR . 'Templates', 0755, true);
        $this->copyDir(
            $frameworkRoot . 'src/Templates/Invitations',
            $vendorFrameworkDir . 'src/Templates/Invitations'
        );
        copy($frameworkRoot . 'stone', $vendorFrameworkDir . 'stone');

        // `stone` requires vendor/autoload.php unconditionally — a stub is
        // sufficient since neither generate-invitations.php nor
        // generate-model.php reference any StoneScriptPHP\* class at runtime
        // (verified by reading both — pure procedural file/string handling).
        file_put_contents($fixture . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', "<?php\n// stub for test fixture\n");

        mkdir($fixture . 'migrations', 0755, true);
        if ($withPriorMigration) {
            copy(
                $frameworkRoot . 'src/Templates/Migrations/base/001_create_users_table.sql',
                $fixture . 'migrations' . DIRECTORY_SEPARATOR . '001_create_users_table.sql'
            );
        }

        if ($withRoutesPhp) {
            mkdir($fixture . 'src' . DIRECTORY_SEPARATOR . 'config', 0755, true);
            file_put_contents(
                $fixture . 'src/config/routes.php',
                "<?php\n\nreturn [\n"
                . "    'GET' => ['/health' => ['handler' => '\\App\\Routes\\HealthRoute']],\n"
                . "    'POST' => [],\n    'PUT' => [],\n    'DELETE' => [],\n    'PATCH' => [],\n];\n"
            );
        }

        return $fixture;
    }

    /**
     * Runs `php stone generate invitations` inside $fixture and returns
     * [exitCode, outputLines].
     */
    private function runGenerator(string $fixture): array
    {
        $stoneBinary = $fixture . 'vendor/progalaxyelabs/stonescriptphp/stone';
        $cmd = 'cd ' . escapeshellarg(rtrim($fixture, DIRECTORY_SEPARATOR))
            . ' && php ' . escapeshellarg($stoneBinary) . ' generate invitations 2>&1';

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        return [$exitCode, $output];
    }

    /**
     * Happy path: a project that already has a numbered migration (like one
     * that ran `php stone generate auth:email-password` first) and a flat
     * v4.0 routes.php.
     */
    public function test_generates_all_expected_files_with_valid_syntax(): void
    {
        $fixture = $this->buildFixture(withPriorMigration: true, withRoutesPhp: true);

        [$exitCode, $output] = $this->runGenerator($fixture);
        $outputText = implode("\n", $output);

        $this->assertSame(0, $exitCode, "generator exited non-zero:\n$outputText");
        $this->assertStringContainsString('Invitation system scaffolding complete', $outputText);

        // Migration — sequential numbering continued from 001.
        $migrationFile = $fixture . 'migrations/002_create_platform_invitations.sql';
        $this->assertFileExists($migrationFile);
        $migrationSql = file_get_contents($migrationFile);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS platform_invitations', $migrationSql);
        // Mandatory destructive-DDL self-check (system prompt §3.3.3 / §1c) — must be clean.
        $this->assertDoesNotMatchRegularExpression(
            '/DROP\s+TABLE|TRUNCATE|DROP\s+DATABASE|DROP\s+SCHEMA/i',
            $migrationSql
        );

        // SQL functions.
        foreach ([
            'create_platform_invitation.pgsql',
            'get_platform_invitation_by_token_hash.pgsql',
            'mark_platform_invitation_accepted.pgsql',
        ] as $fn) {
            $this->assertFileExists($fixture . 'src/postgresql/functions/' . $fn);
        }

        // Model wrappers (php stone generate model output).
        $modelFiles = [
            $fixture . 'src/App/Database/Functions/FnCreatePlatformInvitation.php',
            $fixture . 'src/App/Database/Functions/FnGetPlatformInvitationByTokenHash.php',
            $fixture . 'src/App/Database/Functions/FnMarkPlatformInvitationAccepted.php',
        ];
        foreach ($modelFiles as $file) {
            $this->assertFileExists($file);
            $this->assertPhpSyntaxValid($file);
        }

        // Routes + repository.
        $routeFiles = [
            $fixture . 'src/App/Routes/Invitations/PlatformInvitationRepository.php',
            $fixture . 'src/App/Routes/Invitations/PostCreateInvitationRoute.php',
            $fixture . 'src/App/Routes/Invitations/GetInvitationRoute.php',
            $fixture . 'src/App/Routes/Invitations/PostAcceptInvitationRoute.php',
        ];
        foreach ($routeFiles as $file) {
            $this->assertFileExists($file);
            $this->assertPhpSyntaxValid($file);
        }

        // config/invitations.php
        $configFile = $fixture . 'config/invitations.php';
        $this->assertFileExists($configFile);
        $this->assertPhpSyntaxValid($configFile);

        // routes.php was updated with all 3 new entries + the pre-existing one preserved.
        $routesFile = $fixture . 'src/config/routes.php';
        $this->assertPhpSyntaxValid($routesFile);
        $routes = require $routesFile;
        $this->assertArrayHasKey('/health', $routes['GET'], 'pre-existing route must survive regeneration');
        $this->assertArrayHasKey('/invitations/{token}', $routes['GET']);
        $this->assertTrue($routes['GET']['/invitations/{token}']['is_public']);
        $this->assertArrayHasKey('/invitations', $routes['POST']);
        $this->assertArrayHasKey('/invitations/{token}/accept', $routes['POST']);
        $this->assertTrue($routes['POST']['/invitations/{token}/accept']['is_public']);
        // PostCreateInvitationRoute must NOT be public — it's the
        // authenticated "who can invite" route.
        $this->assertArrayNotHasKey('is_public', $routes['POST']['/invitations']);
    }

    public function test_is_idempotent_on_a_second_run(): void
    {
        $fixture = $this->buildFixture();

        [$exitCode1] = $this->runGenerator($fixture);
        $this->assertSame(0, $exitCode1);

        [$exitCode2, $output2] = $this->runGenerator($fixture);
        $this->assertSame(0, $exitCode2);
        $outputText2 = implode("\n", $output2);

        $this->assertStringContainsString('Skipped (already exists)', $outputText2);

        // Exactly one invitations migration must exist after two runs — not two.
        $migrationCount = 0;
        foreach (scandir($fixture . 'migrations') ?: [] as $entry) {
            if (str_contains($entry, 'create_platform_invitations')) {
                $migrationCount++;
            }
        }
        $this->assertSame(1, $migrationCount, 'a second run must not create a duplicate invitations migration');
    }

    /**
     * DEVIATION FROM THE ORIGINAL DESIGN (documented in
     * cli/generate-invitations.php's resolveMigrationFilename() docblock
     * too): a target with no sequential-numbered migrations falls back to
     * the real fleet's timestamp-based naming convention instead of
     * inventing `001_...` with no basis.
     */
    public function test_falls_back_to_timestamp_migration_naming_with_no_prior_migrations(): void
    {
        $fixture = $this->buildFixture(withPriorMigration: false, withRoutesPhp: false);

        [$exitCode, $output] = $this->runGenerator($fixture);
        $outputText = implode("\n", $output);
        $this->assertSame(0, $exitCode, "generator exited non-zero:\n$outputText");

        $migrationFiles = array_values(array_filter(
            scandir($fixture . 'migrations') ?: [],
            fn($f) => str_contains($f, 'create_platform_invitations')
        ));
        $this->assertCount(1, $migrationFiles);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_create_platform_invitations\.sql$/',
            $migrationFiles[0]
        );
    }

    /**
     * A target with no routes.php at all must not fatal — it should degrade
     * to printing manual registration instructions (still exit 0, since
     * every other artifact was still generated successfully).
     */
    public function test_degrades_gracefully_when_routes_php_is_missing(): void
    {
        $fixture = $this->buildFixture(withPriorMigration: true, withRoutesPhp: false);

        [$exitCode, $output] = $this->runGenerator($fixture);
        $outputText = implode("\n", $output);

        $this->assertSame(0, $exitCode, "generator exited non-zero:\n$outputText");
        $this->assertStringContainsString('routes.php not found', $outputText);
        $this->assertStringContainsString('Manually add these entries', $outputText);
        // Everything else must still have been generated.
        $this->assertFileExists($fixture . 'src/App/Routes/Invitations/PostAcceptInvitationRoute.php');
        $this->assertFileExists($fixture . 'config/invitations.php');
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
