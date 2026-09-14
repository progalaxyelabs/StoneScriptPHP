<?php

declare(strict_types=1);

namespace StoneScriptPHP\Billing\Contracts;

/**
 * The published gateway-code vocabulary — the single spelling both a
 * `pay` driver registry and an `InvoiceSource` implementation's routing
 * DATA (e.g. `inv_gateways.code`) must agree on.
 *
 * This is a VOCABULARY, not logic. It does not decide which rail an
 * invoice uses (that decision lives in the invoicing system's own data —
 * for `stonescriptphp-invoice`, `inv_gateway_routing_rules`). It only
 * fixes the spelling so the two sides can't silently diverge.
 *
 * Adding a new rail = add a const here + a matching `pay` driver + a
 * matching gateway-code row in whatever invoicing system you use. The
 * const makes the three-way agreement explicit and greppable instead of
 * a convention.
 *
 * NOT enforced across the PHP/SQL language boundary (SQL can't import a
 * PHP const) — this is a published contract of record, checked by tests /
 * CI assertions against the reference deployment's active gateway-code set.
 */
final class GatewayCode
{
    public const RAZORPAY = 'razorpay';
    public const PAYPAL = 'paypal';

    private function __construct()
    {
        // static-only
    }

    /**
     * @return list<string> All known gateway codes.
     */
    public static function all(): array
    {
        return [
            self::RAZORPAY,
            self::PAYPAL,
        ];
    }

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::all(), true);
    }
}
