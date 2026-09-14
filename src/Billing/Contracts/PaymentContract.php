<?php

declare(strict_types=1);

namespace StoneScriptPHP\Billing\Contracts;

/**
 * BC alias for {@see PaymentProvider} — the framework's payment contract.
 *
 * Before v9.17.2 this interface `extends` a downstream payment package's
 * own contract interface, purely so framework-owned code could
 * reference a `StoneScriptPHP\Billing\` FQCN without a hard `require` on
 * `stonescriptphp-pay`. That was a backwards dependency: the core
 * framework must not reach across a package boundary for its own contract
 * shape.
 *
 * v9.17.2+: `PaymentProvider` (this same namespace) is now a fully
 * self-contained, framework-owned port — no cross-package `extends`
 * needed at all. This alias is kept only so existing code typed against
 * `PaymentContract` keeps compiling; new code should reference
 * {@see PaymentProvider} directly.
 */
interface PaymentContract extends PaymentProvider
{
}
