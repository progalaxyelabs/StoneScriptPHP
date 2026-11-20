<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;

/**
 * Runs tests/sql/run-tenancy-sql.sh: the generated tenancy SQL against a real PostgreSQL (docker, postgres:16-alpine) in
 * the gateway deploy order. Skips loudly where docker or the image is not available (the script exits 77).
 */
final class TenancySqlTest extends TestCase
{
    public function test_the_generated_sql_behaves_on_a_real_postgresql(): void
    {
        $script = realpath(__DIR__ . '/../../sql/run-tenancy-sql.sh');
        $this->assertNotFalse($script);

        $output = [];
        $status = 0;
        exec('bash ' . escapeshellarg($script) . ' 2>&1', $output, $status);
        $text = implode("\n", $output);

        if ($status === 77) {
            $this->markTestSkipped('tenancy SQL proof needs docker and the postgres:16-alpine image: ' . $text);
        }
        $this->assertSame(0, $status, $text);
        $this->assertStringContainsString('ALL TENANCY SQL CHECKS PASSED', $text);
        $this->assertStringNotContainsString('FAIL', $text);
    }
}
