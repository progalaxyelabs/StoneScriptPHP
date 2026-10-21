<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `php stone validate sqlintegrity` and the RAISE-lint opt-out (`-- stonescript-lint: ignore-file`): every opt-out is
 * a WARNING, and --strict fails on any opt-out outside the project's `lint_ignore_allowed` allow-list.
 */
final class ValidateSqlIntegrityLintIgnoreTest extends TestCase
{
    private const FN_OK = "CREATE OR REPLACE FUNCTION f_ok() RETURNS void AS \$\$\nBEGIN\n    PERFORM 1 FROM things;\nEND;\n\$\$ LANGUAGE plpgsql;\n";
    private const FN_IGNORED = "-- stonescript-lint: ignore-file\nCREATE OR REPLACE FUNCTION f_unsafe(p text) RETURNS void AS \$\$\nBEGIN\n    RAISE EXCEPTION '% exists', p;\nEND;\n\$\$ LANGUAGE plpgsql;\n";

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($d);
        }
    }

    /** @param array<string,string> $functions name => sql */
    private function project(array $functions, ?string $configPhp = null): string
    {
        $root = sys_get_temp_dir() . '/stonescript-lintignore-' . bin2hex(random_bytes(4));
        $pg = $root . '/src/postgresql/main/postgresql';
        mkdir($pg . '/tables', 0775, true);
        mkdir($pg . '/functions', 0775, true);
        file_put_contents($pg . '/tables/things.pgsql', "CREATE TABLE IF NOT EXISTS things (id SERIAL PRIMARY KEY);\n");
        foreach ($functions as $name => $sql) {
            file_put_contents("$pg/functions/$name.pgsql", $sql);
        }
        if ($configPhp !== null) {
            mkdir($root . '/src/config', 0775, true);
            file_put_contents($root . '/src/config/sqlintegrity.php', $configPhp);
        }
        $this->dirs[] = $root;
        return $root;
    }

    /** @return array{0:string,1:int} */
    private function validate(string $root, array $flags = []): array
    {
        $p = proc_open(
            array_merge([PHP_BINARY, __DIR__ . '/../E2E/run_cli.php', 'validate-sqlintegrity.php'], $flags),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => getenv('PATH') ?: '/usr/bin', 'E2E_PROJECT_ROOT' => $root]
        );
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [preg_replace('/\e\[[0-9;]*m/', '', $out) ?? $out, proc_close($p)];
    }

    public function testCleanProjectIsClean(): void
    {
        [$out, $code] = $this->validate($this->project(['f_ok' => self::FN_OK]), ['--strict']);
        $this->assertSame(0, $code, $out);
    }

    public function testIgnoredFileIsAWarningNotAnErrorWithoutStrict(): void
    {
        [$out, $code] = $this->validate($this->project(['f_ok' => self::FN_OK, 'f_unsafe' => self::FN_IGNORED]));
        $this->assertStringContainsString('WARN', $out);
        $this->assertStringContainsString('f_unsafe.pgsql', $out);
        $this->assertStringContainsString('RAISE lint is switched off', $out);
        $this->assertSame(2, $code, "warnings only\n$out");
    }

    public function testStrictFailsOnAnIgnoredFileOutsideTheAllowList(): void
    {
        [$out, $code] = $this->validate($this->project(['f_ok' => self::FN_OK, 'f_unsafe' => self::FN_IGNORED]), ['--strict']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('ERROR', $out);
        $this->assertStringContainsString('not in lint_ignore_allowed', $out);
    }

    public function testStrictPassesForAnAllowListedFixturePathButStillWarns(): void
    {
        $cfg = "<?php\nreturn ['lint_ignore_allowed' => ['src/postgresql/main/postgresql/functions/f_unsafe.pgsql']];\n";
        [$out, $code] = $this->validate($this->project(['f_ok' => self::FN_OK, 'f_unsafe' => self::FN_IGNORED], $cfg), ['--strict']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('WARN', $out);
        $this->assertStringContainsString('[allowed fixture path]', $out);
    }

    public function testAllowListMatchesByPatternAndOnlyThoseFiles(): void
    {
        $cfg = "<?php\nreturn ['lint_ignore_allowed' => ['src/postgresql/main/postgresql/functions/fixture_*.pgsql']];\n";
        [$out, $code] = $this->validate($this->project(['fixture_a' => self::FN_IGNORED, 'real_b' => self::FN_IGNORED], $cfg), ['--strict']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('real_b.pgsql', $out);
        $this->assertStringContainsString('fixture_a.pgsql', $out);
    }

    public function testJsonReportsIgnoredFiles(): void
    {
        [$out, $code] = $this->validate($this->project(['f_unsafe' => self::FN_IGNORED]), ['--json']);
        $j = json_decode($out, true);
        $this->assertSame([['file' => 'src/postgresql/main/postgresql/functions/f_unsafe.pgsql', 'allowed' => false]], $j['lint_ignored']);
    }

    public function testTheOptOutNeverHidesOtherProblemsInOtherFiles(): void
    {
        $bad = "CREATE OR REPLACE FUNCTION f_bad() RETURNS void AS \$\$\nBEGIN\n    RAISE EXCEPTION USING MESSAGE = format('[public:x:409] user %s', 'x');\nEND;\n\$\$ LANGUAGE plpgsql;\n";
        [$out, $code] = $this->validate($this->project(['f_unsafe' => self::FN_IGNORED, 'f_bad' => $bad]));
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('public-interpolation', $out);
    }
}
