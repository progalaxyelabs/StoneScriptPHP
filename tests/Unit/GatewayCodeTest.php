<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Billing\Contracts\GatewayCode;

/**
 * GatewayCode is the published vocabulary contract — the single spelling
 * `pay` drivers and any InvoiceSource's routing DATA must agree on. This
 * test is the reference-deployment CI assertion: `stonescriptphp-invoice`'s
 * seed data (034_invoice_persistence_tables.pgsql `inv_gateways` seed
 * rows) uses exactly 'razorpay' and 'paypal' — these constants must never
 * silently diverge from that set.
 */
class GatewayCodeTest extends TestCase
{
    public function test_all_returns_the_known_reference_deployment_codes(): void
    {
        $this->assertSame(['razorpay', 'paypal'], GatewayCode::all());
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
