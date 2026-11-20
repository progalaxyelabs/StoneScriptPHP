<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Tenancy\Platform\SecretsFile;

/**
 * @covers \StoneScriptPHP\Tenancy\Platform\SecretsFile
 */
final class SecretsFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ssp-secrets-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            ($f->isLink() || $f->isFile()) ? unlink($f->getPathname()) : rmdir($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_the_last_duplicate_is_read_and_the_last_duplicate_is_replaced(): void
    {
        $path = $this->dir . '/.env';
        file_put_contents($path, "A_KEY=first\nOTHER=1\nA_KEY=second\n");
        $file = new SecretsFile($path);

        $this->assertSame('second', $file->get('A_KEY'), 'dotenv: the last one wins');

        $file->upsert(['A_KEY' => 'third']);

        $this->assertSame("A_KEY=first\nOTHER=1\nA_KEY=third\n", (string) file_get_contents($path));
        $this->assertSame('third', $file->get('A_KEY'), 'what is read after the write is what was written');
    }

    public function test_a_symlinked_file_is_written_through_and_the_link_stays(): void
    {
        $real = $this->dir . '/real.env';
        file_put_contents($real, "KEEP=1\n");
        chmod($real, 0640);
        $link = $this->dir . '/.env';
        symlink($real, $link);

        (new SecretsFile($link))->upsert(['AUTH_V2_CLIENT_ID' => 'client-1']);

        $this->assertTrue(is_link($link), 'the link is still a link');
        $this->assertSame($real, realpath($link));
        $this->assertSame("KEEP=1\nAUTH_V2_CLIENT_ID=client-1\n", (string) file_get_contents($real));
        $this->assertSame(0640, fileperms($real) & 0777);
        $this->assertSame('client-1', (new SecretsFile($link))->get('AUTH_V2_CLIENT_ID'));
    }

    public function test_a_dangling_symlink_is_refused_not_replaced(): void
    {
        $link = $this->dir . '/.env';
        symlink($this->dir . '/missing.env', $link);

        try {
            (new SecretsFile($link))->upsert(['A_KEY' => 'x']);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException) {
        }
        $this->assertTrue(is_link($link));
        $this->assertNull((new SecretsFile($link))->get('A_KEY'));
    }
}
