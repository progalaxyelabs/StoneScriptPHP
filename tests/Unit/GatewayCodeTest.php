<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Billing\Contracts\GatewayCode;

/**
 * GatewayCode is the published vocabulary contract — the single spelling
 * `pay` drivers and any InvoiceSource's routing DATA must agree on.
 *
 * test_all_returns_the_known_reference_deployment_codes() below is a
 * PINNING test — it asserts today's published constant list is exactly
 * `['razorpay', 'paypal']`, so a change to GatewayCode::all() is a
 * deliberate, reviewed diff, never an accidental one. It does NOT read
 * `stonescriptphp-invoice`'s SQL seed data, so it cannot by itself catch
 * the two packages' vocabularies drifting apart (the framework
 * intentionally has no dependency on `invoice`'s repository layout, so a
 * hard path-based cross-check here would be fragile for anyone running
 * this suite without that sibling checkout present).
 *
 * test_matches_reference_invoice_seed_data_if_present() below is the
 * actual cross-package drift check — it locates a sibling
 * `stonescriptphp-invoice` checkout (as exists in this monorepo's dev
 * layout) and diffs its `inv_gateways` seed codes against
 * GatewayCode::all() when found, skipping (not failing) when it isn't.
 */
class GatewayCodeTest extends TestCase
{
    public function test_all_returns_the_known_reference_deployment_codes(): void
    {
        $this->assertSame(['razorpay', 'paypal'], GatewayCode::all());
    }

    public function test_matches_reference_invoice_seed_data_if_present(): void
    {
        $candidates = [
            __DIR__ . '/../../../stonescriptphp-invoice/src/Invoicing/Schema/main/034_invoice_persistence_tables.pgsql',
        ];

        $seedFile = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $seedFile = $candidate;
                break;
            }
        }

        if ($seedFile === null) {
            $this->markTestSkipped(
                'No sibling stonescriptphp-invoice checkout found at the expected monorepo-relative '
                . 'path — this cross-package drift check only runs when that checkout is present '
                . '(e.g. in the dev monorepo layout), never as a hard requirement of this test suite.'
            );
        }

        $sql = file_get_contents($seedFile);
        $this->assertIsString($sql);

        $this->assertMatchesRegularExpression(
            '/INSERT INTO inv_gateways/',
            $sql,
            "Expected an 'INSERT INTO inv_gateways' seed statement in $seedFile — the file shape "
            . 'changed; update this test\'s parsing, do not just delete the check.'
        );

        // Scope the extraction to ONLY the inv_gateways INSERT statement
        // (start marker through its terminating ';') — a naive whole-file
        // regex would also match unrelated `('foo', 'bar', ...)` tuples
        // elsewhere (e.g. inv_invoice_status_transitions seed rows). SQL
        // line comments are stripped FIRST — a `--` comment can itself
        // contain a literal ';' (this file has one: "...row); Razorpay
        // Standard Checkout"), which would otherwise terminate the block
        // early and silently drop later rows from the check.
        $sqlNoComments = preg_replace('/--.*$/m', '', $sql);
        $this->assertIsString($sqlNoComments);

        $startPos = strpos($sqlNoComments, 'INSERT INTO inv_gateways');
        $this->assertIsInt($startPos);
        $endPos = strpos($sqlNoComments, ';', $startPos);
        $this->assertIsInt($endPos, 'inv_gateways INSERT statement has no terminating ; — unexpected file shape.');
        $insertBlock = substr($sqlNoComments, $startPos, $endPos - $startPos);

        preg_match_all("/\(\s*'([a-z_]+)',\s*'[^']*',/", $insertBlock, $matches);
        $seedCodes = array_values(array_unique($matches[1] ?? []));
        sort($seedCodes);

        $publishedCodes = GatewayCode::all();
        sort($publishedCodes);

        $this->assertSame(
            $publishedCodes,
            $seedCodes,
            'GatewayCode::all() has diverged from stonescriptphp-invoice\'s inv_gateways seed data — '
            . 'the two packages\' gateway-code vocabularies must be added/renamed together.'
        );
    }

    public function test_constants_match_all(): void
    {
        $this->assertSame('razorpay', GatewayCode::RAZORPAY);
        $this->assertSame('paypal', GatewayCode::PAYPAL);
        $this->assertContains(GatewayCode::RAZORPAY, GatewayCode::all());
        $this->assertContains(GatewayCode::PAYPAL, GatewayCode::all());
    }

    public function test_is_known_true_for_published_codes(): void
    {
        $this->assertTrue(GatewayCode::isKnown('razorpay'));
        $this->assertTrue(GatewayCode::isKnown('paypal'));
    }

    public function test_is_known_false_for_unpublished_code(): void
    {
        $this->assertFalse(GatewayCode::isKnown('stripe'));
        $this->assertFalse(GatewayCode::isKnown(''));
        // Case-sensitive on purpose — the spelling is the contract.
        $this->assertFalse(GatewayCode::isKnown('Razorpay'));
    }
}
