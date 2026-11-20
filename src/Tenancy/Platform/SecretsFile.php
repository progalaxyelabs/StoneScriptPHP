<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Platform;

/**
 * Upserts KEY=VALUE lines in a runtime secrets env file (for example the application's `.env`).
 *
 * The write is atomic (temp file in the same directory, then rename) and the file keeps its existing
 * mode; a file created here is 0600. Other lines, comments and their order are preserved. A symlinked file
 * is written THROUGH the link (the target is replaced, the link stays). When a key appears more than once the
 * LAST occurrence is the one that is read and replaced (dotenv: the last one wins), so an older duplicate can
 * never shadow the new value. Values that could break out of a line (newline, quotes, whitespace, `#`, `$`,
 * backslash) are refused rather than escaped, so a malformed value can never inject another variable.
 */
final class SecretsFile
{
    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /** The file that is really read and written: the symlink target when the path is a link. */
    private function target(): string
    {
        if (is_link($this->path)) {
            $real = realpath($this->path);
            if ($real === false) {
                throw new \RuntimeException('Secrets file is a dangling symlink');
            }
            return $real;
        }
        return $this->path;
    }

    public function get(string $key): ?string
    {
        try {
            $target = $this->target();
        } catch (\RuntimeException) {
            return null;
        }
        if (!is_file($target)) {
            return null;
        }
        $found = null;
        foreach (file($target, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?' . preg_quote($key, '/') . '\s*=\s*(.*)$/', $line, $m) === 1) {
                $value = trim(trim($m[1]), "\"'");
                $found = $value === '' ? null : $value; // the last occurrence wins
            }
        }
        return $found;
    }

    /** @param array<string,string> $values */
    public function upsert(array $values): void
    {
        foreach ($values as $key => $value) {
            if (preg_match('/\A[A-Z][A-Z0-9_]*\z/', (string) $key) !== 1) {
                throw new \InvalidArgumentException('Invalid variable name');
            }
            if ($value === '' || preg_match('/[\s"\'#$\\\\`]/', $value) === 1) {
                throw new \InvalidArgumentException("Refusing to write an unsafe value for $key");
            }
        }

        $target = $this->target();
        $lines = is_file($target) ? (file($target, FILE_IGNORE_NEW_LINES) ?: []) : [];
        foreach ($values as $key => $value) {
            $last = null;
            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*(?:export\s+)?' . preg_quote((string) $key, '/') . '\s*=/', $line) === 1) {
                    $last = $i;
                }
            }
            if ($last === null) {
                $lines[] = $key . '=' . $value;
            } else {
                $lines[$last] = $key . '=' . $value;
            }
        }

        $dir = dirname($target);
        if (!is_dir($dir)) {
            throw new \RuntimeException('Secrets file directory does not exist');
        }
        $mode = is_file($target) ? (fileperms($target) & 0777) : 0600;
        $tmp = $dir . DIRECTORY_SEPARATOR . '.' . basename($target) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $handle = fopen($tmp, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('Could not write the secrets file');
        }
        chmod($tmp, 0600);
        $ok = fwrite($handle, implode("\n", $lines) . "\n") !== false;
        $ok = fclose($handle) && $ok;
        if (!$ok || !chmod($tmp, $mode) || !rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write the secrets file');
        }
    }
}
