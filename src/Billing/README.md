# StoneScriptPHP\Billing — the pay <-> invoice integration seam

The framework owns the CONTRACT (and a reference implementation of the
glue) between any payment module (`stonescriptphp-pay` or a hand-rolled
`PaymentProvider`) and any invoicing/billing system (`stonescriptphp-invoice`
or a hand-rolled `InvoiceSource`). Neither `pay` nor `invoice` knows about
the other — this namespace is the ONLY place that binds both, so each stays
independently installable and usable on its own.

- `pay` alone = works (no invoicing dependency at all).
- `invoice` alone = works (SQL-only: issue invoices, run the CRM chase,
  mark paid manually — no PHP adapter needed).
- `pay` + `invoice` + this package = automated collection, wired through
  `CollectionOrchestrator`.

Using `StoneScriptPHP\Billing\` implies you have
`progalaxyelabs/stonescriptphp-pay` installed (the framework itself does
NOT `require` it — only `require-dev`, for this package's own tests — so a
project that never touches `Billing\` never needs `pay` installed).

## The two contracts

- `Contracts\InvoiceSource` — what the framework needs from ANY invoicing
  system: `resolvePayableIntent(string $invoiceRef): PayableIntent` and
  `recordVerifiedPayment(RecordPaymentRequest $req): RecordPaymentResult`.
  Two methods, not three — recording a payment and transitioning the
  invoice to paid are folded into ONE atomic call on purpose, so the
  "should this settle?" decision stays in the invoicing implementation
  (SQL, for `stonescriptphp-invoice`), never in PHP.
- `\StoneScriptPay\Contracts\PaymentProvider` (from `stonescriptphp-pay`) —
  already an adequate payment contract; not redesigned here. Every driver
  additionally reports `settlementModel(): 'gateway'|'mor'`.
- `Contracts\GatewayCode` — the published gateway-code vocabulary
  (`'razorpay'`, `'paypal'`, ...) both sides' data must agree on. A
  vocabulary, not logic — it fixes the spelling, never the routing
  decision.

## `CollectionOrchestrator` — the two flows

```php
use StoneScriptPHP\Billing\CollectionOrchestrator;
use StoneScriptPHP\Billing\Contracts\GatewayCode;
use StoneScriptPay\Drivers\RazorpayDriver;
// use StoneScriptPHP\Invoice\Php\InvoiceSourceAdapter; // from stonescriptphp-invoice

$payment = new RazorpayDriver($keyId, $keySecret, $webhookSecret);
$invoices = new InvoiceSourceAdapter(/* ... */); // or null under MoR

$orchestrator = new CollectionOrchestrator($payment, $invoices, GatewayCode::RAZORPAY);

// Flow 1 — initiate collection (server redirects the payer to the returned checkout)
$checkout = $orchestrator->initiateCollection($invoiceRef);
if (!$checkout->isPayable) {
    // e.g. already_paid — render "no button", do not create an order
}
// redirect to $checkout->checkoutEndpoint with $checkout->orderId / publishableKeyId

// Flow 2 — settle on webhook (called from the provider webhook route)
$outcome = $orchestrator->settleFromWebhook(new \StoneScriptPay\DTO\WebhookRequest(
    rawBody: file_get_contents('php://input'),
    signature: $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '',
));
// ack 2xx whenever $outcome->wasHandled — including a replay ($outcome->alreadyRecorded)
```

The orchestrator contains ONLY sequencing + marshalling — no amount, tax,
numbering, currency, or gateway-ROUTING decision is ever made in PHP. See
`CollectionOrchestrator`'s docblock and
`Tests\Unit\BillingBusinessLogicAuditTest` for the line-by-line proof.

## Four integration paths — the contract is OPTIONAL, never a forced coupling

- **Path A — our `pay` + our `invoice` (reference pairing).** `composer
  require` both + the framework; register `RazorpayDriver`/`PaypalDriver`
  and `InvoiceSourceAdapter`; use `CollectionOrchestrator` as above.
- **Path B — our `pay` + a DIFFERENT invoicing system** (Zoho, QuickBooks,
  Stripe Invoicing, hand-rolled). Implement `InvoiceSource`'s two methods
  against your system — `resolvePayableIntent` reads your invoice's
  balance/currency/gateway choice; `recordVerifiedPayment` posts a payment
  to your ledger idempotently. Wire your implementation into
  `CollectionOrchestrator`. `pay` and the orchestrator are unchanged; you
  never touch `stonescriptphp-invoice`.
- **Path C — an MoR provider, NO separate invoicing.** Implement a
  `PaymentProvider` driver whose `settlementModel()` returns `'mor'`.
  Construct `new CollectionOrchestrator($driver, null, $gatewayCode)`.
  `settleFromWebhook()` acknowledges without recording;
  `initiateCollection()` is not used (there is no invoice to resolve a
  payable intent from) — initiate checkout directly against the driver
  instead. A simple/MoR project is never forced to adopt `invoice`.
- **Path D — a DIFFERENT payment module + our `invoice`.** Implement
  `PaymentProvider` for your provider; keep `InvoiceSourceAdapter`. Add
  your provider's gateway code to `GatewayCode` + `inv_gateways` + your
  routing rules. Orchestrator unchanged.

## What this seam does NOT do

- Does not embed/iframe a checkout — `initiateCollection()` returns
  redirect info for the CALLER to send the payer to a hosted checkout /
  central pay page (`accounts.{platform}.tld` / a central pay service),
  never an in-app iframe or renew banner.
- Does not compute amounts, tax, currency conversion, invoice numbering, or
  gateway ROUTING — those live in the invoicing system's own data/SQL
  (e.g. `stonescriptphp-invoice`'s `inv_*` functions).
- Does not re-verify a webhook signature — verification lives entirely in
  the bound `PaymentProvider`; the orchestrator only calls
  `handleWebhook()` and trusts its result.
- Does not build the central pay-link token, a `pay.*` service, dunning/
  email collection, or the invoice outbox relay — those are separate,
  already-decided workstreams this seam is defined to run correctly
  inside, but does not build.
