<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

/**
 * Builds a throwaway project directory that runs the repository's `stone` the way an installed
 * project does (vendor mode): <project>/stone, <project>/vendor/autoload.php and
 * <project>/vendor/progalaxyelabs/stonescriptphp pointing at the framework under test.
 */
final class CliProjectHarness
{
    public string $dir;

    public function __construct(bool $stubFramework = false)
    {
        $repo = dirname(__DIR__, 2);
        $this->dir = sys_get_temp_dir() . '/ssp-cli-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/vendor/progalaxyelabs', 0700, true);
        file_put_contents($this->dir . '/vendor/autoload.php', "<?php\nrequire " . var_export($repo . '/vendor/autoload.php', true) . ";\n");
        copy($repo . '/stone', $this->dir . '/stone');
        if ($stubFramework) {
            mkdir($this->dir . '/vendor/progalaxyelabs/stonescriptphp/cli/helpers', 0700, true);
            copy($repo . '/cli/helpers/color.php', $this->dir . '/vendor/progalaxyelabs/stonescriptphp/cli/helpers/color.php');
        } else {
            symlink($repo, $this->dir . '/vendor/progalaxyelabs/stonescriptphp');
        }
    }

    public function write(string $relative, string $contents): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * @param array<string,string> $env
     * @return array{0:int,1:string} exit status, stdout+stderr
     */
    public function stone(array $args, array $env = []): array
    {
        $cmd = array_merge([PHP_BINARY, $this->dir . '/stone'], $args);
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            ['PATH' => (string) getenv('PATH')] + $env
        );
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out];
    }

    public function cleanup(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            ($f->isLink() || $f->isFile()) ? unlink($f->getPathname()) : rmdir($f->getPathname());
        }
        rmdir($this->dir);
    }
}
