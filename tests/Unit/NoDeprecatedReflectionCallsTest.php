<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Constructs deprecated in PHP 8.5 that shipped code (src/, cli/) must not use:
 * ReflectionProperty/Method::setAccessible() (no-op since 8.1), curl_close() (no-op since 8.0) and the
 * implicit $http_response_header local (use http_get_last_response_headers()).
 */
final class NoDeprecatedReflectionCallsTest extends TestCase
{
    public function test_shipped_code_uses_no_php85_deprecated_constructs(): void
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
                    if (is_array($tok) && $tok[0] === T_STRING && in_array($tok[1], ['setAccessible', 'curl_close'], true)) {
                        $offenders[] = $file->getPathname() . ':' . $tok[2];
                    }
                    if (is_array($tok) && $tok[0] === T_VARIABLE && $tok[1] === '$http_response_header') {
                        $offenders[] = $file->getPathname() . ':' . $tok[2];
                    }
                }
            }
        }
        $this->assertSame([], $offenders);
    }
}
