<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The generated TypeScript client must handle HTTP 423 (subscription ended ->
 * read-only) as a distinct, typed ReadOnlyError: not a token refresh, not a
 * toast, with the X-Subscription-State header surfaced to a notice listener.
 *
 * Static assertions always run; the behavioural run (compile the generated
 * client with tsc, execute tests/Fixtures/ts/client-423.test.cjs under node
 * with a fake fetch) runs when a sibling tsc and node are available.
 */
final class ClientGeneratorReadOnly423Test extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/ssp-ro423-' . uniqid();
        mkdir($this->tmp . '/src/config', 0755, true);
        file_put_contents($this->tmp . '/src/config/routes.php', "<?php\nreturn ['GET' => ['/portal/tenant/{tenantId}/items' => ['handler' => 'ListItemsRoute', 'service' => 'portal', 'group' => 'inventory']]];\n");

        $root = realpath(__DIR__ . '/../..');
        $wrapper = $this->tmp . '/wrap.php';
        $args = var_export(['generator', 'portal', '--output=' . $this->tmp . '/out', '--tenancy=T3'], true);
        file_put_contents($wrapper, "<?php\n"
            . "define('ROOT_PATH','{$this->tmp}/'); define('SRC_PATH','{$this->tmp}/src/'); define('CONFIG_PATH','{$this->tmp}/src/config/'); define('DEBUG_MODE',1); define('INDEX_START_TIME',microtime(true));\n"
            . "require_once '$root/vendor/autoload.php';\n"
            . "\$argv = $args; \$_SERVER['argv'] = \$argv; \$_SERVER['argc'] = count(\$argv);\n"
            . "require '$root/cli/generate-client.php';\n");
        exec(PHP_BINARY . ' ' . escapeshellarg($wrapper) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->tmp);
        }
    }

    public function test_generated_files_carry_read_only_contract(): void
    {
        $src = $this->tmp . '/out/portal/src/';
        $errors = file_get_contents($src . 'errors.ts');
        $http   = file_get_contents($src . 'http.ts');
        $client = file_get_contents($src . 'client.ts');
        $index  = file_get_contents($src . 'index.ts');

        $this->assertStringContainsString('export class ReadOnlyError extends ApiError', $errors);
        $this->assertStringContainsString("'READ_ONLY_TRIAL_EXPIRED'", $errors);
        $this->assertStringContainsString('export function isReadOnlyError', $errors);
        $this->assertStringContainsString('export function parseSubscriptionStateHeader', $errors);
        $this->assertStringContainsString("response.status === 423", $http);
        $this->assertStringContainsString('if (err instanceof ReadOnlyError) return;', $http);
        $this->assertStringContainsString('setSubscriptionNoticeListener', $http);
        $this->assertStringContainsString('setSubscriptionNoticeListener', $client);
        $this->assertStringContainsString('ReadOnlyError', $index);
        $this->assertStringNotContainsString('this.tokens.clear();', $http);
    }

    public function test_generated_client_behaves_per_contract_under_node(): void
    {
        $sibling = dirname(realpath(__DIR__ . '/../..'));
        $tsc = null;
        foreach (['stonescriptphp-client-core', 'ngx-stonescriptphp-client'] as $pkg) {
            $c = $sibling . '/' . $pkg . '/node_modules/.bin/tsc';
            if (is_executable($c)) {
                $tsc = $c;
                break;
            }
        }
        $node = trim((string) shell_exec('command -v node'));
        if ($tsc === null || $node === '') {
            $this->markTestSkipped('tsc (sibling package) and node required for the behavioural run.');
        }

        $pkgDir = $this->tmp . '/out/portal';
        exec(escapeshellarg($tsc) . ' -p ' . escapeshellarg($pkgDir) . ' --module commonjs --moduleResolution node --outDir ' . escapeshellarg($this->tmp . '/cjs') . ' 2>&1', $o1, $c1);
        $this->assertSame(0, $c1, "generated client must compile:\n" . implode("\n", $o1));

        $test = realpath(__DIR__ . '/../Fixtures/ts/client-423.test.cjs');
        exec('GEN_CJS=' . escapeshellarg($this->tmp . '/cjs') . ' ' . escapeshellarg($node) . ' ' . escapeshellarg($test) . ' 2>&1', $o2, $c2);
        $this->assertSame(0, $c2, implode("\n", $o2));
        $this->assertStringContainsString('423 OK', implode("\n", $o2));
    }
}
