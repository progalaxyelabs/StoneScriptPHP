<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\PHPStan\Rules\RawEnvAccessRule;

/**
 * Asserts the advisory RawEnvAccessRule behaves as specified by running the
 * framework's own PHPStan (vendor/bin/phpstan) with rules.neon against the
 * tests/Fixtures/RawEnvSample.php fixture and inspecting the JSON report.
 *
 * The fixture intentionally contains:
 *   - 4 raw-access sites that MUST be flagged
 *     (getenv, $_ENV[], $_SERVER[], putenv)
 *   - 1 inline-silenced site (@phpstan-ignore) that MUST NOT be flagged
 *   - 1 Env::secret() site that MUST NOT be flagged
 *
 * The fixture is excluded from the framework's own phpstan.neon, so we point
 * PHPStan at it explicitly via a generated temp config that `includes:`
 * rules.neon. This is an integration assertion that mirrors exactly what a
 * consuming platform experiences.
 */
final class RawEnvAccessRuleTest extends TestCase
{
    private string $frameworkRoot;
    private string $tempConfig = '';

    protected function setUp(): void
    {
        $this->frameworkRoot = realpath(__DIR__ . '/../..') ?: '';
        self::assertNotSame('', $this->frameworkRoot);

        if (!is_file($this->frameworkRoot . '/vendor/bin/phpstan')) {
            self::markTestSkipped('phpstan not installed (run composer install)');
        }
    }

    protected function tearDown(): void
    {
        if ($this->tempConfig !== '' && is_file($this->tempConfig)) {
            @unlink($this->tempConfig);
        }
    }

    public function testRuleIdentifierIsStable(): void
    {
        // The identifier is the @phpstan-ignore handle platforms depend on.
        self::assertSame('stonescriptphp.rawEnvAccess', RawEnvAccessRule::IDENTIFIER);
    }

    public function testRuleFiresOnRawAccessAndIsSilencedAppropriately(): void
    {
        $report = $this->runPhpStanOnFixture();

        $fixture = $this->frameworkRoot . '/tests/Fixtures/RawEnvSample.php';
        $messages = $report['files'][$fixture]['messages'] ?? [];

        $rawEnvMessages = array_values(array_filter(
            $messages,
            static fn (array $m): bool => ($m['identifier'] ?? null) === RawEnvAccessRule::IDENTIFIER
        ));

        // Exactly the 4 unsilenced raw-access sites must be flagged:
        // getenv() (l21), $_ENV[] (l27), $_SERVER[] (l33), putenv() (l39).
        self::assertCount(
            4,
            $rawEnvMessages,
            'Expected exactly 4 RawEnvAccessRule advisories on the fixture; got: '
                . json_encode($rawEnvMessages)
        );

        $flaggedLines = array_map(static fn (array $m): int => (int) $m['line'], $rawEnvMessages);

        // The inline-silenced site (@phpstan-ignore, ~l46) and the
        // Env::secret() site (~l53) must NOT appear.
        foreach ($rawEnvMessages as $m) {
            self::assertStringContainsString(
                'StoneScriptPHP\\Env',
                $m['message'],
                'Advisory message should reference the Env class'
            );
        }

        // Sanity: the two lowest-numbered flagged lines are the getenv/$_ENV
        // sites near the top of the fixture, and none of the flagged lines is
        // beyond the putenv() site (i.e. the silenced/Env::secret sites later
        // in the file produced no advisory).
        sort($flaggedLines);
        self::assertLessThanOrEqual(
            45,
            max($flaggedLines),
            'No advisory should fire on the silenced or Env::secret() sites'
        );
    }

    /**
     * @return array{files?: array<string, array{messages?: array<int, array{line:int, message:string, identifier?:string}>}>}
     */
    private function runPhpStanOnFixture(): array
    {
        $fixture = $this->frameworkRoot . '/tests/Fixtures/RawEnvSample.php';
        $rulesNeon = $this->frameworkRoot . '/rules.neon';

        $this->tempConfig = tempnam(sys_get_temp_dir(), 'sspphpstan_') . '.neon';
        $config = <<<NEON
            includes:
                - {$rulesNeon}
            parameters:
                level: 5
                paths:
                    - {$fixture}
                reportUnmatchedIgnoredErrors: false
                ignoreErrors:
                    - '#Function (res_ok|res_error|auth_check|auth_id|auth_user|log_info|log_error|log_debug|log_warn) not found#'
            NEON;
        file_put_contents($this->tempConfig, $config);

        $cmd = sprintf(
            '%s %s analyse --error-format=json --no-progress -c %s 2>/dev/null',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->frameworkRoot . '/vendor/bin/phpstan'),
            escapeshellarg($this->tempConfig)
        );

        $output = shell_exec($cmd);
        self::assertIsString($output, 'phpstan produced no output');

        /** @var array $decoded */
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded, 'phpstan JSON report did not decode: ' . $output);

        return $decoded;
    }
}
