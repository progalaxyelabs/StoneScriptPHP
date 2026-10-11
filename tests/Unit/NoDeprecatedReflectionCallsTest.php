<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ReflectionProperty/Method::setAccessible() is a no-op since PHP 8.1 and deprecated in 8.5.
 * Shipped code (src/, cli/) must not call it.
 */
final class NoDeprecatedReflectionCallsTest extends TestCase
{
    public function test_shipped_code_does_not_call_set_accessible(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach (['src', 'cli'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                foreach (token_get_all((string) file_get_contents($file->getPathname())) as $i => $tok) {
                    if (is_array($tok) && $tok[0] === T_STRING && $tok[1] === 'setAccessible') {
                        $offenders[] = $file->getPathname() . ':' . $tok[2];
                    }
                }
            }
        }
        $this->assertSame([], $offenders);
    }
}
