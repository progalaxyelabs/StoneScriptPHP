<?php

declare(strict_types=1);

namespace StoneScriptPHP\Billing\Contracts;

use StoneScriptPHP\Billing\Dto\CreateOrderRequest;
use StoneScriptPHP\Billing\Dto\CreateSubscriptionRequest;
use StoneScriptPHP\Billing\Dto\OrderResult;
use StoneScriptPHP\Billing\Dto\RefundRequest;
use StoneScriptPHP\Billing\Dto\RefundResult;
use StoneScriptPHP\Billing\Dto\SubscriptionResult;
use StoneScriptPHP\Billing\Dto\VerificationResult;
use StoneScriptPHP\Billing\Dto\VerifyRequest;
use StoneScriptPHP\Billing\Dto\WebhookEvent;
use StoneScriptPHP\Billing\Dto\WebhookRequest;
use StoneScriptPHP\Billing\Exceptions\PaymentException;
use StoneScriptPHP\Billing\Exceptions\SignatureVerificationException;
use StoneScriptPHP\Billing\Exceptions\WebhookException;

/**
 * The framework-owned payment provider PORT (ports-and-adapters: the
 * framework defines what it needs from a payment module; any payment
 * module — `progalaxyelabs/stonescriptphp-pay` or a hand-rolled one —
 * ADAPTS to this, never the other way around).
 *
 * Before v9.17.2 the framework's `Billing\Contracts\PaymentContract`
 * `extends` a downstream payment package's own contract interface — a
 * backwards dependency where the core framework reached across a package
 * boundary into a downstream payment package for its own contract shape.
 * This interface is the fix: a self-contained port, owned by the
 * framework, referencing only framework DTOs/exceptions (`Billing\Dto\*`,
 * `Billing\Exceptions\*`).
 *
 * `stonescriptphp-pay` stays a STANDALONE, framework-free library — it does
 * NOT implement this interface and has no dependency on this framework (so
 * it remains independently publishable/requireable without pulling in
 * StoneScriptPHP). Its own `StoneScriptPay\Contracts\PaymentProvider` is a
 * structurally identical, separately-owned contract. A consuming
 * application that wants to use `pay`'s drivers (e.g. `RazorpayDriver`)
 * through THIS port writes a small adapter class implementing
 * `StoneScriptPHP\Billing\Contracts\PaymentProvider` that delegates to a
 * `pay` driver instance, mapping `StoneScriptPay\DTO\*` <-> `Billing\Dto\*`
 * at each call (the shapes mirror each other 1:1, so the mapping is
 * mechanical) — see `Billing/README.md` for an illustrative adapter. Any
 * OTHER payment module, or a hand-rolled driver, can instead implement
 * this interface directly with zero adapter needed.
 *
 * Every driver/adapter (Razorpay, Paddle, Square, Apple Pay, …) implements
 * this contract. Consuming code is coupled only to this contract — not to
 * any provider SDK, and not to any specific payment PACKAGE.
 *
 * Security invariants:
 *
 *   1. createOrder() sets the amount SERVER-SIDE. The amount in CreateOrderRequest
 *      MUST come from the server's own plan/price data, never from client input.
 *
 *   2. verifySignature() MUST be called before treating any checkout response as
 *      authoritative. Throw SignatureVerificationException on mismatch.
 *
 *   3. handleWebhook() MUST verify the provider signature before parsing the event.
 *      Throw SignatureVerificationException on mismatch; WebhookException on parse errors.
 *
 * Tax/settlement note:
 *   Gateway providers (Razorpay) — the consuming application is the merchant of record
 *   and is responsible for GST invoicing. MoR providers (Paddle) — the provider is the
 *   seller of record and handles tax. Drivers should surface which model they use.
 *
 * @package StoneScriptPHP\Billing\Contracts
 */
interface PaymentProvider
{
    /**
     * Create a payment order / checkout intent with a SERVER-SET amount.
     *
     * Returns an OrderResult containing the order ID and publishable key ID to
     * pass to the frontend for the hosted checkout.
     *
     * @param CreateOrderRequest $req
     * @return OrderResult
     * @throws PaymentException if the provider API call fails.
     */
    public function createOrder(CreateOrderRequest $req): OrderResult;

    /**
     * Verify the HMAC signature returned by the hosted checkout.
     *
     * Must be called before treating a payment response as authoritative.
     * Returns a VerificationResult on success.
     *
     * @param VerifyRequest $req
     * @return VerificationResult
     * @throws SignatureVerificationException if the HMAC does not match.
     */
    public function verifySignature(VerifyRequest $req): VerificationResult;

    /**
     * Handle an inbound provider webhook.
     *
     * Verifies the webhook signature, parses the event, and returns a
     * normalised WebhookEvent. The route handler dispatches on event type.
     *
     * @param WebhookRequest $req
     * @return WebhookEvent
     * @throws SignatureVerificationException if the webhook signature is invalid.
     * @throws WebhookException if the payload is malformed or the event is unreadable.
     */
    public function handleWebhook(WebhookRequest $req): WebhookEvent;

    /**
     * Create a recurring subscription.
     *
     * @param CreateSubscriptionRequest $req
     * @return SubscriptionResult
     * @throws PaymentException if the provider API call fails.
     */
    public function createSubscription(CreateSubscriptionRequest $req): SubscriptionResult;

    /**
     * Cancel a subscription.
     *
     * For most providers cancellation is effective at the end of the current
     * billing period — verify in the driver docs.
     *
     * @param string $subscriptionId Provider-assigned subscription ID.
     * @return SubscriptionResult Updated subscription state.
     * @throws PaymentException if the provider API call fails.
     */
    public function cancelSubscription(string $subscriptionId): SubscriptionResult;

    /**
     * Fetch current subscription state from the provider.
     *
     * @param string $subscriptionId Provider-assigned subscription ID.
     * @return SubscriptionResult Current subscription state.
     * @throws PaymentException if the provider API call fails or the subscription is not found.
     */
    public function getSubscription(string $subscriptionId): SubscriptionResult;

    /**
     * Refund a payment (full or partial).
     *
     * @param RefundRequest $req
     * @return RefundResult
     * @throws PaymentException if the provider API call fails.
     */
    public function refund(RefundRequest $req): RefundResult;

    /**
     * Which settlement model this driver implements.
     *
     * GATEWAY — the consuming application is the merchant of record and is
     *   responsible for GST/tax invoicing (e.g. Razorpay, PayPal-as-gateway).
     * MOR — the provider is the seller of record and handles tax itself
     *   (e.g. Paddle, LemonSqueezy).
     *
     * A consumer wiring `StoneScriptPHP\Billing\CollectionOrchestrator`
     * uses this to decide whether an `InvoiceSource` is expected: under
     * MOR the orchestrator is constructed with a `null` InvoiceSource and
     * `settleFromWebhook()` simply acknowledges without recording locally.
     *
     * @return string 'gateway' | 'mor' — see {@see SettlementModel}.
     */
    public function settlementModel(): string;
}
