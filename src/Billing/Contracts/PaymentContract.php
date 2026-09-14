<?php

declare(strict_types=1);

namespace StoneScriptPHP\Billing\Contracts;

/**
 * The framework's published alias for THE payment contract.
 *
 * `progalaxyelabs/stonescriptphp-pay`'s `\StoneScriptPay\Contracts\PaymentProvider`
 * IS the payment contract — it does not need redesigning. This interface
 * exists purely so framework-owned code (and consumers) can reference a
 * `StoneScriptPHP\Billing\` FQCN instead of reaching across package
 * boundaries by hand, WITHOUT giving the framework a require-time
 * dependency on `pay`.
 *
 * This file is only ever loaded (autoloaded) when something actually
 * references `PaymentContract` — a project that never touches `Billing\`
 * never triggers the `\StoneScriptPay\Contracts\PaymentProvider` class
 * lookup this `extends` clause performs, so the framework's own
 * composer.json deliberately does NOT `require` `stonescriptphp-pay`.
 * Using `CollectionOrchestrator` (which type-hints `PaymentProvider`
 * directly, not this alias) or `PaymentContract` implies your project has
 * `progalaxyelabs/stonescriptphp-pay` installed.
 *
 * Every `pay` driver (RazorpayDriver, PaypalDriver, a future PaddleDriver,
 * ...) already implements `PaymentProvider` and therefore already
 * satisfies `PaymentContract` — no extra work needed on the driver side.
 */
interface PaymentContract extends \StoneScriptPay\Contracts\PaymentProvider
{
}
