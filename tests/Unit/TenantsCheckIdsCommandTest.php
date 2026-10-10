<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** `php stone tenants:check-ids`: read-only, lists ids failing the tenant-id rule and collisions. */
final class TenantsCheckIdsCommandTest extends TestCase
{
    /** @return array{0:int,1:string} */
    private function runCli(string $ids, string $extraArgs = '--platform=myplatform --schema=tenant'): array
    {
        $file = tempnam(sys_get_temp_dir(), 'ids');
        file_put_contents($file, $ids);
        $script = realpath(__DIR__ . '/../../cli/tenants-check-ids.php');
        $out = [];
        exec(PHP_BINARY . ' ' . escapeshellarg($script) . ' --file=' . escapeshellarg($file) . ' ' . $extraArgs . ' 2>&1', $out, $code);
        @unlink($file);
        return [$code, implode("\n", $out)];
    }

    public function test_clean_ids_exit_zero(): void
    {
        [$code, $out] = $this->runCli("3f2504e0-4f89-41d3-9a0c-0305e82c3301\nclinic-1\n");
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('2 id(s) checked, 0 rejected, 0 collision group(s)', $out);
    }

    public function test_rejects_and_collisions_are_listed_and_exit_one(): void
    {
        [$code, $out] = $this->runCli("a-b\na_b\nUPPER\n" . str_repeat('z', 60) . "\n");
        $this->assertSame(1, $code);
        $this->assertStringContainsString('REJECT  "a_b"', $out);
        $this->assertStringContainsString('REJECT  "UPPER"', $out);
        $this->assertStringContainsString('COLLIDE myplatform_tenant_a_b', $out);
        $this->assertStringContainsString('63', $out);
    }

    public function test_trailing_newline_inside_an_id_line_is_reported_not_trimmed(): void
    {
        [$code, $out] = $this->runCli("abc \n");
        $this->assertSame(1, $code);
        $this->assertStringContainsString('REJECT', $out);
    }

    public function test_usage_error_without_platform_and_schema(): void
    {
        [$code] = $this->runCli("a\n", '');
        $this->assertSame(2, $code);
    }

    public function test_sample_generator_validates_the_real_generator_and_does_not_read_stdin(): void
    {
        $script = realpath(__DIR__ . '/../../cli/tenants-check-ids.php');
        $out = [];
        exec(PHP_BINARY . ' ' . escapeshellarg($script) . ' --platform=myplatform --schema=tenant --sample-generator=25 </dev/null 2>&1', $out, $code);
        $text = implode("\n", $out);
        $this->assertSame(0, $code, $text);
        $this->assertStringContainsString('sampled 25 id(s) from', $text);
        $this->assertStringContainsString('generator sample: 25 id(s), 0 rejected.', $text);
    }

    public function test_sample_generator_fails_when_the_generator_can_emit_a_refused_id(): void
    {
        require_once __DIR__ . '/../Fixtures/BadIdGeneratorRoute.php';
        $script = realpath(__DIR__ . '/../../cli/tenants-check-ids.php');
        $out = [];
        $cmd = PHP_BINARY . ' -d auto_prepend_file=' . escapeshellarg((string) realpath(__DIR__ . '/../Fixtures/BadIdGeneratorRoute.php'))
            . ' ' . escapeshellarg($script) . ' --platform=myplatform --schema=tenant --sample-generator=3'
            . ' --generator-class=' . escapeshellarg('StoneScriptPHP\\Tests\\Fixtures\\BadIdGeneratorRoute') . ' </dev/null 2>&1';
        exec($cmd, $out, $code);
        $text = implode("\n", $out);
        $this->assertSame(1, $code, $text);
        $this->assertStringContainsString('GENERATOR-REJECT', $text);
    }

    public function test_sample_generator_unknown_class_is_a_usage_error(): void
    {
        $script = realpath(__DIR__ . '/../../cli/tenants-check-ids.php');
        $out = [];
        exec(PHP_BINARY . ' ' . escapeshellarg($script) . ' --platform=p --schema=tenant --sample-generator --generator-class=Nope\\Missing </dev/null 2>&1', $out, $code);
        $this->assertSame(2, $code);
    }
}
