<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A freshly generated Google route must load (no missing config file), live in the namespace the
 * generator prints, and take its client id from the environment.
 */
final class GenerateAuthGoogleRouteTest extends TestCase
{
    private string $base = '';
    private string $proj = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssp_auth_' . bin2hex(random_bytes(6));
        $this->proj = $this->base . '/api';
        mkdir($this->proj, 0700, true);
        // Generator falls back to a sibling "StoneScriptPHP" dir when there is no vendor copy.
        symlink(dirname(__DIR__, 2), $this->base . '/StoneScriptPHP');
    }

    protected function tearDown(): void
    {
        $files = [
            $this->proj . '/src/App/Routes/Auth/GoogleOauthRoute.php',
            $this->proj . '/src/App/Routes/Auth/LinkedinOauthRoute.php',
        ];
        foreach ($files as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        foreach (['src/App/Routes/Auth', 'src/App/Routes', 'src/App', 'src', 'migrations'] as $d) {
            if (is_dir($this->proj . '/' . $d)) {
                foreach (scandir($this->proj . '/' . $d) as $e) {
                    if ($e !== '.' && $e !== '..' && is_file($this->proj . '/' . $d . '/' . $e)) {
                        unlink($this->proj . '/' . $d . '/' . $e);
                    }
                }
                rmdir($this->proj . '/' . $d);
            }
        }
        rmdir($this->proj);
        unlink($this->base . '/StoneScriptPHP');
        rmdir($this->base);
    }

    /** @param array<string,string> $envVars @return array{0:string,1:int} */
    private function child(string $body, array $envVars = []): array
    {
        $repo = dirname(__DIR__, 2);
        $code = 'define("ROOT_PATH", ' . var_export($this->proj . DIRECTORY_SEPARATOR, true) . ');'
            . 'require ' . var_export($repo . '/vendor/autoload.php', true) . ';' . $body;
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH')] + $envVars
        );
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [$out, proc_close($proc)];
    }

    private function generate(string $provider): array
    {
        $script = var_export(dirname(__DIR__, 2) . '/cli/generate-auth.php', true);
        return $this->child('$argv = ["generate", "auth:' . $provider . '"]; $argc = 2; require ' . $script . ';');
    }

    public function test_generated_google_route_has_no_missing_config_dependency_and_uses_documented_namespace(): void
    {
        [$out, $code] = $this->generate('google');
        $this->assertSame(0, $code, $out);

        $route = (string) file_get_contents($this->proj . '/src/App/Routes/Auth/GoogleOauthRoute.php');
        $this->assertStringNotContainsString('google-oauth.php', $route);
        $this->assertStringNotContainsString('CONFIG_PATH', $route);
        $this->assertStringContainsString('namespace App\Routes\Auth;', $route);
        // The namespace printed in the next-steps matches the generated file.
        $this->assertStringContainsString('\App\Routes\Auth\GoogleOauthRoute::class', $out);
        $this->assertFileDoesNotExist($this->proj . '/src/config/google-oauth.php');
    }

    public function test_generated_google_route_loads_and_fails_closed_without_client_id(): void
    {
        $this->generate('google');
        $file = var_export($this->proj . '/src/App/Routes/Auth/GoogleOauthRoute.php', true);
        $body = 'require ' . $file . ';'
            . '$r = new App\Routes\Auth\GoogleOauthRoute(); $r->credential = "x";'
            . '$res = $r->process(); echo "RESULT:" . $res->status . ":" . $res->message;';
        [$out] = $this->child($body, ['DB_GATEWAY_URL' => 'http://g:9000', 'DB_GATEWAY_PLATFORM' => 'p']);
        $this->assertStringContainsString('RESULT:error:Google Sign-In is not configured', $out);
    }

    public function test_generated_google_route_reads_client_id_from_env(): void
    {
        $this->generate('google');
        $file = var_export($this->proj . '/src/App/Routes/Auth/GoogleOauthRoute.php', true);
        $body = 'require ' . $file . ';'
            . '$r = new App\Routes\Auth\GoogleOauthRoute(); $r->credential = "not-a-jwt";'
            . '$res = $r->process(); echo "RESULT:" . $res->status . ":" . $res->message;';
        [$out] = $this->child($body, [
            'DB_GATEWAY_URL' => 'http://g:9000', 'DB_GATEWAY_PLATFORM' => 'p', 'GOOGLE_CLIENT_ID' => 'abc.apps.googleusercontent.com',
        ]);
        // Configured -> it proceeds to verify the (invalid) token instead of reporting "not configured".
        $this->assertStringNotContainsString('not configured', $out);
        $this->assertStringContainsString('RESULT:', $out);
    }

    public function test_provider_without_a_shipped_template_fails_before_generating_anything(): void
    {
        [$out, $code] = $this->generate('linkedin');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('not available', $out);
        $this->assertDirectoryDoesNotExist($this->proj . '/migrations');
    }
}
