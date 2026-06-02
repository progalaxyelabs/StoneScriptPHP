<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Gateway CLI option-parsing tests (#2810).
 *
 * Verifies that parseGatewayOptions() maps the granular --allow-* safety flags
 * to the correct gateway allow-tokens, handles --dangerously-skip-verification,
 * and preserves the back-compat --force allow-all escape hatch.
 */
class GatewayOptionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/cli/helpers/gateway-common.php';
    }

    public function test_no_safety_flags_yields_empty_allow_and_false_bools(): void
    {
        $o = parseGatewayOptions(['stone', 'gateway:migrate-main']);
        $this->assertSame([], $o['allow']);
        $this->assertFalse($o['force']);
        $this->assertFalse($o['skip_verification']);
    }

    public function test_each_allow_flag_maps_to_its_token(): void
    {
        $cases = [
            '--allow-drop-table'          => 'drop_table',
            '--allow-drop-column'         => 'drop_column',
            '--allow-column-type-change'  => 'modify_column_type',
            '--allow-add-not-null-column' => 'add_not_null_column',
            '--allow-set-not-null'        => 'set_not_null',
        ];
        foreach ($cases as $flag => $token) {
            $o = parseGatewayOptions(['stone', 'gateway:migrate-main', $flag]);
            $this->assertSame([$token], $o['allow'], "flag {$flag} must map to {$token}");
            // Granular allow must NOT imply force or skip_verification.
            $this->assertFalse($o['force'], "{$flag} must not set force");
            $this->assertFalse($o['skip_verification'], "{$flag} must not skip verification");
        }
    }

    public function test_multiple_allow_flags_accumulate(): void
    {
        $o = parseGatewayOptions([
            'stone', 'gateway:migrate-main',
            '--allow-drop-column', '--allow-column-type-change',
        ]);
        $this->assertContains('drop_column', $o['allow']);
        $this->assertContains('modify_column_type', $o['allow']);
        $this->assertCount(2, $o['allow']);
    }

    public function test_dangerously_skip_verification_sets_only_that_gate(): void
    {
        $o = parseGatewayOptions(['stone', 'gateway:migrate-main', '--dangerously-skip-verification']);
        $this->assertTrue($o['skip_verification']);
        $this->assertSame([], $o['allow'], 'skip-verification must not grant any allow token');
        $this->assertFalse($o['force']);
    }

    public function test_force_is_backcompat_allow_all_and_independent_of_granular(): void
    {
        // --force alone: the gateway expands force=true to allow-all + skip-verify;
        // the CLI just forwards the boolean and an empty granular allow list.
        $o = parseGatewayOptions(['stone', 'gateway:migrate-main', '--force']);
        $this->assertTrue($o['force']);
        $this->assertSame([], $o['allow']);
        $this->assertFalse($o['skip_verification']);
    }

    public function test_unknown_flag_does_not_populate_allow(): void
    {
        $o = parseGatewayOptions(['stone', 'gateway:migrate-main', '--allow-everything']);
        $this->assertSame([], $o['allow']);
    }
}
