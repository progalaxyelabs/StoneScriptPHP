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

The framework OWNS the payment-provider port itself
(`Contracts\PaymentProvider` + `Dto\*` + `Exceptions\*`, all
self-contained — no cross-package `extends`/`use`). The framework never
depends on any payment package, and `stonescriptphp-pay` never depends on
the framework — both stay independently publishable/requireable.

`stonescriptphp-pay` ships its OWN, structurally-identical
`StoneScriptPay\Contracts\PaymentProvider` contract (same method names,
separate DTO namespace `StoneScriptPay\DTO\*`) — it does not implement
this framework port directly. To use a `pay` driver (e.g.
`RazorpayDriver`) through THIS port, the CONSUMING APPLICATION writes a
small adapter — see "Bridging `stonescriptphp-pay` into this port" below.
A hand-rolled driver, or any other payment package, can instead implement
`Contracts\PaymentProvider` directly with no adapter needed. The framework
itself resolves and compiles with zero payment package present.

## The two contracts

- `Contracts\InvoiceSource` — what the framework needs from ANY invoicing
  system: `resolvePayableIntent(string $invoiceRef): PayableIntent` and
  `recordVerifiedPayment(RecordPaymentRequest $req): RecordPaymentResult`.
  Two methods, not three — recording a payment and transitioning the
  invoice to paid are folded into ONE atomic call on purpose, so the
  "should this settle?" decision stays in the invoicing implementation
  (SQL, for `stonescriptphp-invoice`), never in PHP.
- `Contracts\PaymentProvider` — the framework's OWN payment port (see
  above). `stonescriptphp-pay`'s drivers do NOT implement this interface
  directly (see the bridging section below); a hand-rolled driver or a
  small app-side adapter does. Every implementation additionally reports
  `settlementModel(): 'gateway'|'mor'`.
- `Contracts\GatewayCode` — the published gateway-code vocabulary
  (`'razorpay'`, `'paypal'`, ...) both sides' data must agree on. A
  vocabulary, not logic — it fixes the spelling, never the routing
  decision.

## `CollectionOrchestrator` — the two flows

```php
use StoneScriptPHP\Billing\CollectionOrchestrator;
use StoneScriptPHP\Billing\Contracts\GatewayCode;
use StoneScriptPHP\Billing\Dto\WebhookRequest;
// use App\Billing\StoneScriptPayAdapter; // your own adapter — see below
// use StoneScriptPHP\Invoice\Php\InvoiceSourceAdapter; // from stonescriptphp-invoice

$payment = new StoneScriptPayAdapter(new \StoneScriptPay\Drivers\RazorpayDriver($keyId, $keySecret, $webhookSecret));
$invoices = new InvoiceSourceAdapter(/* ... */); // or null under MoR

// gatewayCode is REQUIRED, no default — pass the code that matches $payment.
// (A defaulted value here would let a non-Razorpay integrator silently
// mis-tag every recorded payment with the wrong gateway code.)
$orchestrator = new CollectionOrchestrator($payment, GatewayCode::RAZORPAY, $invoices);

// Flow 1 — initiate collection (server redirects the payer to the returned checkout)
$checkout = $orchestrator->initiateCollection($invoiceRef);
if (!$checkout->isPayable) {
    // e.g. already_paid — render "no button", do not create an order
}
// redirect to $checkout->checkoutEndpoint with $checkout->orderId / publishableKeyId

// Flow 2 — settle on webhook (called from the provider webhook route)
$outcome = $orchestrator->settleFromWebhook(new WebhookRequest(
    rawBody: file_get_contents('php://input'),
    signature: $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '',
));
// ack 2xx whenever $outcome->wasHandled — including a replay ($outcome->alreadyRecorded)
```

The orchestrator contains ONLY sequencing + marshalling — no amount, tax,
numbering, currency, or gateway-ROUTING decision is ever made in PHP. See
`CollectionOrchestrator`'s docblock and
`StoneScriptPHP\Tests\Unit\BillingBusinessLogicAuditTest` for the line-by-line proof.

## Bridging `stonescriptphp-pay` into this port

`pay` is a deliberately framework-free library — it has no dependency on
`progalaxyelabs/stonescriptphp` and its drivers implement its OWN
`StoneScriptPay\Contracts\PaymentProvider`, not this framework's port.
Both contracts are structurally identical (same methods, mirrored DTO
fields), so bridging is a thin, mechanical delegate-and-map adapter that
YOUR APPLICATION owns (it lives in neither core package):

```php
namespace App\Billing;

use StoneScriptPHP\Billing\Contracts\PaymentProvider;
use StoneScriptPHP\Billing\Dto as FwDto;
use StoneScriptPay\Contracts\PaymentProvider as PayProvider;
use StoneScriptPay\DTO as PayDto;

final class StoneScriptPayAdapter implements PaymentProvider
{
    public function __construct(private readonly PayProvider $driver) {}

    public function createOrder(FwDto\CreateOrderRequest $req): FwDto\OrderResult
    {
        $r = $this->driver->createOrder(new PayDto\CreateOrderRequest(
            amountMinorUnits: $req->amountMinorUnits,
            currency: $req->currency,
            receipt: $req->receipt,
            notes: $req->notes,
        ));

        return new FwDto\OrderResult(
            orderId: $r->orderId,
            amountMinorUnits: $r->amountMinorUnits,
            currency: $r->currency,
            receipt: $r->receipt,
            publishableKeyId: $r->publishableKeyId,
            status: $r->status,
            raw: $r->raw,
        );
    }

    public function handleWebhook(FwDto\WebhookRequest $req): FwDto\WebhookEvent
    {
        $e = $this->driver->handleWebhook(new PayDto\WebhookRequest($req->rawBody, $req->signature, $req->headers ?? []));

        return new FwDto\WebhookEvent(
            type: $e->type,
            providerEvent: $e->providerEvent,
            payload: $e->payload,
            raw: $e->raw,
            invoiceRef: $e->invoiceRef,
            gatewayTxnRef: $e->gatewayTxnRef,
            amountMinorUnits: $e->amountMinorUnits,
            currency: $e->currency,
            capturedAt: $e->capturedAt,
        );
    }

    public function settlementModel(): string
    {
        return $this->driver->settlementModel();
    }

    // ...verifySignature() / createSubscription() / cancelSubscription() /
    // getSubscription() / refund() follow the same delegate-and-map shape.
}
```

Exceptions (`PaymentException`, `SignatureVerificationException`,
`WebhookException`) also exist in both namespaces with the same names and
meaning — either let the `StoneScriptPay\Exceptions\*` ones propagate (a
caller catching the framework's `Billing\Exceptions\*` types would then
need to catch both), or re-throw the framework's own exception type from
inside the adapter for a cleaner boundary. Pick one and be consistent.

## Four integration paths — the contract is OPTIONAL, never a forced coupling

- **Path A — our `pay` + our `invoice` (reference pairing).** `composer
  require` both + the framework; write (or reuse) the adapter above to
  bridge `RazorpayDriver`/`PaypalDriver` into `PaymentProvider`; use
  `InvoiceSourceAdapter`; wire `CollectionOrchestrator` as above.
- **Path B — our `pay` + a DIFFERENT invoicing system** (Zoho, QuickBooks,
  Stripe Invoicing, hand-rolled). Implement `InvoiceSource`'s two methods
  against your system — `resolvePayableIntent` reads your invoice's
  balance/currency/gateway choice; `recordVerifiedPayment` posts a payment
  to your ledger idempotently. Wire your implementation into
  `CollectionOrchestrator`. `pay` and the orchestrator are unchanged; you
  never touch `stonescriptphp-invoice`.
- **Path C — an MoR provider, NO separate invoicing.** Implement a
  `PaymentProvider` driver whose `settlementModel()` returns `'mor'`.
  Construct `new CollectionOrchestrator($driver, $gatewayCode)` (the third
  arg, `$invoices`, defaults to `null`).
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
