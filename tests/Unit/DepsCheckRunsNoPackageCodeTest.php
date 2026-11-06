<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `php stone deps:check` and `php stone post-install` must not execute any installed package's code.
 * The fixture package's autoload "files" entry writes a marker file; the marker must stay absent while the
 * package is still reported.
 */
final class DepsCheckRunsNoPackageCodeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/cli/helpers/post-install.php';
        $this->root = sys_get_temp_dir() . '/ssp-nocode-' . bin2hex(random_bytes(6));
        $fw = $this->root . '/vendor/progalaxyelabs/stonescriptphp';
        mkdir($fw, 0755, true);
        $repo = dirname(__DIR__, 2);
        foreach (['cli', 'src', 'config'] as $dir) {
            $this->copyTree("$repo/$dir", "$fw/$dir");
        }
        foreach (['stone', 'composer.json'] as $f) {
            copy("$repo/$f", "$fw/$f");
        }
        copy("$repo/stone", $this->root . '/stone');
        file_put_contents("$fw/framework-manifest.txt", ssp_format_manifest('14.4.0', ssp_build_framework_manifest($fw)));

        mkdir($this->root . '/vendor/fixture/marker', 0755, true);
        mkdir($this->root . '/vendor/composer', 0755, true);
        $marker = $this->root . '/MARKER';
        file_put_contents($this->root . '/vendor/fixture/marker/boot.php', "<?php file_put_contents('" . $marker . "', 'ran');");
        // The autoloader a real install produces: it includes every package's autoload "files".
        file_put_contents($this->root . '/vendor/autoload.php', "<?php require __DIR__ . '/fixture/marker/boot.php';");
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode([
            'packages' => [
                ['name' => 'progalaxyelabs/stonescriptphp', 'version' => '14.4.0'],
                ['name' => 'fixture/marker', 'version' => '1.0.0', 'autoload' => ['files' => ['boot.php']]],
            ],
            'dev-package-names' => [],
        ]));
        file_put_contents($this->root . '/composer.json', json_encode(['config' => ['allow-plugins' => false]]));
        file_put_contents($this->root . '/stone-packages.json', json_encode(['approved' => ['vendors' => ['progalaxyelabs', 'fixture']]]));
    }

    protected function tearDown(): void
    {
        ssp_rrmdir($this->root);
    }

    private function copyTree(string $from, string $to): void
    {
        mkdir($to, 0755, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $target = $to . substr($item->getPathname(), strlen($from));
            $item->isDir() ? @mkdir($target, 0755, true) : copy($item->getPathname(), $target);
        }
    }

    /** @return array{0:int,1:string} */
    private function stone(string $command): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root . '/stone') . ' ' . $command . ' 2>&1';
        exec('cd ' . escapeshellarg($this->root) . ' && ' . $cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function test_deps_check_reports_the_package_and_runs_none_of_its_code(): void
    {
        [$code, $out] = $this->stone('deps:check --no-audit');
        $this->assertFileDoesNotExist($this->root . '/MARKER', 'package code ran during deps:check');
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('unreviewed autoload "files"', $out);
        $this->assertStringContainsString('fixture/marker', $out);
    }

    public function test_post_install_runs_none_of_the_packages_code(): void
    {
        $this->stone('post-install');
        $this->assertFileDoesNotExist($this->root . '/MARKER', 'package code ran during post-install');
    }

    public function test_the_fixture_does_run_when_the_autoloader_is_loaded(): void
    {
        // Control: proves the marker mechanism works, so the absence above is meaningful.
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg("require '" . $this->root . "/vendor/autoload.php';"));
        $this->assertFileExists($this->root . '/MARKER');
    }
}
