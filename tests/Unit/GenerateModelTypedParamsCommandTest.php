<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Real, end-to-end test of `php stone generate model <fn>.pgsql`
 * (cli/generate-model.php) for the typed-params-object input work
 * (Commit 3 of the array->TypedArray sweep, see the "Database typed
 * boundary" work in Database.php): the generator now emits a
 * `{Function}Params` class (public properties in SQL declaration order,
 * DEFAULT'd args nullable) and a `run($params)` that calls
 * `Database::fnTyped()`, instead of a flat list of scalar arguments.
 *
 * Same fixture-building approach as GenerateInvitationsCommandTest — a real
 * (non-symlinked) `vendor/progalaxyelabs/stonescriptphp` install so `stone`'s
 * and the generator's own ROOT_PATH auto-detection resolve correctly.
 *
 * @covers cli/generate-model.php
 */
final class GenerateModelTypedParamsCommandTest extends TestCase
{
    /** @var string[] */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $this->deleteDir($fixture);
        }
        $this->fixtures = [];
        parent::tearDown();
    }

    private function buildFixture(): string
    {
        $frameworkRoot = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR;
        $fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ssp_genmodel_fixture_' . uniqid('', true) . DIRECTORY_SEPARATOR;
        $this->fixtures[] = $fixture;

        $vendorFrameworkDir = $fixture . 'vendor' . DIRECTORY_SEPARATOR . 'progalaxyelabs' . DIRECTORY_SEPARATOR . 'stonescriptphp' . DIRECTORY_SEPARATOR;
        mkdir($vendorFrameworkDir, 0755, true);
        $this->copyDir($frameworkRoot . 'cli', $vendorFrameworkDir . 'cli');
        copy($frameworkRoot . 'stone', $vendorFrameworkDir . 'stone');
        file_put_contents($fixture . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', "<?php\n// stub for test fixture\n");

        mkdir($fixture . 'src/postgresql/functions', 0755, true);

        return $fixture;
    }

    /** @return array{0: int, 1: string} [exitCode, combined output] */
    private function runGenerator(string $fixture, string $fnFile): array
    {
        $stoneBinary = $fixture . 'vendor/progalaxyelabs/stonescriptphp/stone';
        $cmd = 'cd ' . escapeshellarg(rtrim($fixture, DIRECTORY_SEPARATOR))
            . ' && php ' . escapeshellarg($stoneBinary) . ' generate model ' . escapeshellarg($fnFile) . ' 2>&1';

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    public function test_mixed_required_and_default_params_emit_ordered_typed_params_class(): void
    {
        $fixture = $this->buildFixture();

        // p_a (required), p_b (required), p_c (DEFAULT -> optional/nullable).
        // Order is deliberately non-alphabetical to prove declaration order
        // (not sorted order) is what's emitted.
        file_put_contents(
            $fixture . 'src/postgresql/functions/create_widget.pgsql',
            "CREATE OR REPLACE FUNCTION create_widget(\n"
            . "    p_c text default null,\n"
            . "    p_a uuid,\n"
            . "    p_b integer\n"
            . ")\n"
            . "RETURNS TABLE (\n"
            . "    o_widget_id uuid,\n"
            . "    o_name text\n"
            . ")\n"
            . "AS \$\$\n"
            . "BEGIN\nEND;\n"
            . "\$\$ LANGUAGE plpgsql;\n"
        );

        [$exitCode, $output] = $this->runGenerator($fixture, 'create_widget.pgsql');
        $this->assertSame(0, $exitCode, "generator exited non-zero:\n$output");

        $file = $fixture . 'src/App/Database/Functions/FnCreateWidget.php';
        $this->assertFileExists($file);
        $this->assertPhpSyntaxValid($file);

        $php = file_get_contents($file);

        // Params class exists, with properties in DECLARATION order (c, a, b) —
        // not alphabetical, not required-first.
        $this->assertStringContainsString('class CreateWidgetParams', $php);
        $cPos = strpos($php, 'public ?string $p_c');
        $aPos = strpos($php, 'public string $p_a;');
        $bPos = strpos($php, 'public int $p_b;');
        $this->assertNotFalse($cPos);
        $this->assertNotFalse($aPos);
        $this->assertNotFalse($bPos);
        $this->assertTrue($cPos < $aPos && $aPos < $bPos, 'params class properties must preserve SQL declaration order');

        // The DEFAULT'd param is nullable with a `= null` default; the
        // required ones are not.
        $this->assertStringContainsString('public ?string $p_c = null;', $php);
        $this->assertStringContainsString('public string $p_a;', $php);
        $this->assertStringContainsString('public int $p_b;', $php);

        // run() takes the params object and calls Database::fnTyped().
        $this->assertStringContainsString('public static function run(CreateWidgetParams $params): TypedArray', $php);
        $this->assertStringContainsString('Database::fnTyped($function_name, $params);', $php);
        $this->assertStringContainsString('Database::result_as_typed_table(', $php);
    }

    public function test_zero_argument_function_emits_no_params_class(): void
    {
        $fixture = $this->buildFixture();

        file_put_contents(
            $fixture . 'src/postgresql/functions/get_health.pgsql',
            "CREATE OR REPLACE FUNCTION get_health()\n"
            . "RETURNS TABLE (\n"
            . "    o_status text\n"
            . ")\n"
            . "AS \$\$\n"
            . "BEGIN\nEND;\n"
            . "\$\$ LANGUAGE plpgsql;\n"
        );

        [$exitCode, $output] = $this->runGenerator($fixture, 'get_health.pgsql');
        $this->assertSame(0, $exitCode, "generator exited non-zero:\n$output");

        $file = $fixture . 'src/App/Database/Functions/FnGetHealth.php';
        $this->assertFileExists($file);
        $this->assertPhpSyntaxValid($file);

        $php = file_get_contents($file);
        $this->assertStringNotContainsString('Params', $php, 'a zero-argument function must not emit a params class');
        $this->assertStringContainsString('public static function run(): TypedArray', $php);
        $this->assertStringContainsString('Database::fn($function_name, []);', $php);
    }

    /**
     * Nullability-drift guard, layer 1: a `--` comment INSIDE the parameter
     * parens must not be silently parsed into a corrupted (and possibly
     * wrongly-non-nullable) parameter -- the generator must refuse instead.
     *
     * This reproduces a real gotcha discovered in a fleet platform's own SQL:
     * a maintainer had to move an explanatory comment OUTSIDE the parameter
     * list specifically because an inline comment there broke the model
     * generator. That footgun should no longer be possible to trip silently.
     */
    public function test_inline_comment_inside_param_list_fails_loud(): void
    {
        $fixture = $this->buildFixture();

        file_put_contents(
            $fixture . 'src/postgresql/functions/create_order.pgsql',
            "CREATE OR REPLACE FUNCTION create_order(\n"
            . "    p_customer_id integer, -- required\n"
            . "    p_notes text default null\n"
            . ")\n"
            . "RETURNS TABLE (\n"
            . "    o_order_id integer\n"
            . ")\n"
            . "AS \$\$\n"
            . "BEGIN\nEND;\n"
            . "\$\$ LANGUAGE plpgsql;\n"
        );

        [$exitCode, $output] = $this->runGenerator($fixture, 'create_order.pgsql');

        $this->assertNotSame(0, $exitCode, "generator must fail loud on an inline '--' comment inside the param list, but exited 0:\n$output");
        $this->assertStringContainsString('create_order', $output);
        $this->assertStringContainsString('comment', $output);
        $this->assertFileDoesNotExist($fixture . 'src/App/Database/Functions/FnCreateOrder.php');
    }

    /**
     * Nullability-drift guard, layer 1 (identifier safety): a parameter name
     * the parser cannot resolve to a plain identifier must fail loud rather
     * than emit a DTO with a garbage property name/type.
     */
    public function test_unparseable_default_expression_still_emits_correct_nullability(): void
    {
        $fixture = $this->buildFixture();

        // A DEFAULT expression that itself contains a comma-like function
        // call and an explicit cast -- exercises split_parameters()' paren
        // depth tracking plus the DEFAULT-stripping regex together. Must
        // still come out nullable, not silently required.
        file_put_contents(
            $fixture . 'src/postgresql/functions/create_ticket.pgsql',
            "CREATE OR REPLACE FUNCTION create_ticket(\n"
            . "    p_subject text,\n"
            . "    p_priority integer default greatest(1, least(5, 3))\n"
            . ")\n"
            . "RETURNS TABLE (\n"
            . "    o_ticket_id integer\n"
            . ")\n"
            . "AS \$\$\n"
            . "BEGIN\nEND;\n"
            . "\$\$ LANGUAGE plpgsql;\n"
        );

        [$exitCode, $output] = $this->runGenerator($fixture, 'create_ticket.pgsql');
        $this->assertSame(0, $exitCode, "generator exited non-zero:\n$output");

        $php = file_get_contents($fixture . 'src/App/Database/Functions/FnCreateTicket.php');
        $this->assertStringContainsString('public string $p_subject;', $php);
        $this->assertStringContainsString('public ?int $p_priority = null;', $php);
    }

    /**
     * A DEFAULT'd JSON/JSONB parameter -- a common, everyday pattern (an
     * items/metadata payload) -- must NOT emit `?mixed`, which is a genuine
     * PHP fatal error ("Type mixed cannot be marked as nullable since mixed
     * already includes null"). `mixed` is implicitly nullable already.
     * Discovered via the schema-contract proof harness against a real
     * production function with exactly this shape.
     */
    public function test_default_jsonb_param_emits_bare_mixed_not_nullable_mixed(): void
    {
        $fixture = $this->buildFixture();

        file_put_contents(
            $fixture . 'src/postgresql/functions/create_shipment.pgsql',
            "CREATE OR REPLACE FUNCTION create_shipment(\n"
            . "    p_carrier text,\n"
            . "    p_items jsonb default '[]'::jsonb\n"
            . ")\n"
            . "RETURNS TABLE (\n"
            . "    o_shipment_id integer\n"
            . ")\n"
            . "AS \$\$\n"
            . "BEGIN\nEND;\n"
            . "\$\$ LANGUAGE plpgsql;\n"
        );

        [$exitCode, $output] = $this->runGenerator($fixture, 'create_shipment.pgsql');
        $this->assertSame(0, $exitCode, "generator exited non-zero:\n$output");

        $file = $fixture . 'src/App/Database/Functions/FnCreateShipment.php';
        $this->assertPhpSyntaxValid($file);

        $php = file_get_contents($file);
        $this->assertStringContainsString('public mixed $p_items = null;', $php);
        $this->assertStringNotContainsString('?mixed', $php);
    }

    private function assertPhpSyntaxValid(string $file): void
    {
        $output = [];
        $returnVar = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $returnVar);
        $this->assertSame(0, $returnVar, "php -l failed for $file:\n" . implode("\n", $output));
    }

    private function copyDir(string $src, string $dst): void
    {
        mkdir($dst, 0755, true);
        foreach (scandir($src) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $srcPath = $src . DIRECTORY_SEPARATOR . $item;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $item;
            if (is_dir($srcPath)) {
                $this->copyDir($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->deleteDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
