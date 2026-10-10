# Persistence contract: a failed write is never a 2xx

A write that quietly did nothing (zero rows back, a `{success:false}` envelope, a swallowed database error)
used to be answerable with `res_ok(...)` and HTTP 200. The framework now has one write boundary, one error
classifier and one response guard.

## 1. `Database::mutate()` / `mutateTyped()`: the write boundary

```php
// instead of Database::fn('update_order', [...])
$rows = Database::mutate('update_order', [$id, $total], notFoundMessage: 'Order not found.');
```

Throws `StoneScriptPHP\Persistence\PersistenceException` (typed; `httpStatusCode()`, `reason()`, `function()`,
`errorCode()`, `isPublic()`; the status also travels in `getCode()`):

| signal | status | message shown to the client |
|---|---|---|
| **public business error** (envelope with `public_message`, or a `[public:...]` RAISE) | its own 4xx (default 400) | the function's `public_message`, as-is, plus `error_code` / `fields` / `errors` |
| `{error: "..."}` envelope (swallowed DB error) | 500 | generic sentence |
| `{success:false, message}` envelope WITHOUT `public_message` | 400 | generic sentence |
| no row where one was expected | 404 with `notFoundMessage`, else 500 | `notFoundMessage` / generic |
| row count != `exactRows` | 500 (404 if 0 rows and `notFoundMessage`) | generic |
| database refused the statement | classified, see below | generic classified sentence (or a public one) |

**Privacy contract.** Raw function/database text is NEVER the exception message and NEVER reaches a client, on any
path (typed or not, debug mode included). It lives only in `getPrevious()` (never public; platform code may inspect
it with `DbErrorMapper::causeContains($e, 'constraint_name')`). Log lines are scrubbed by `LogSanitizer`: `Key (col)=(value)`,
`DETAIL:` text and every quoted literal are removed; identifiers (constraint/table/column names) stay for diagnosis.

### Raising a user-facing business error (the convention)

A function reports a rule violation the user should read in ONE of two explicit, opt-in ways:

1. **Result envelope** (a row, or one JSON column holding it; `o_` prefixes accepted):
   ```sql
   RETURN json_build_object(
     'error_code', 'insufficient_quantity',
     'public_message', 'Insufficient quantity in selected warehouse',
     'status', 400,                                   -- optional, 4xx only (default 400)
     'fields', json_build_object('warehouse_id', p_warehouse), -- optional, surfaced under data.fields
     'errors', json_build_array(json_build_object('field','qty','message','Too many'))); -- optional list
   ```
   Surfaced as `{status:error, message:<public_message>, data:{error_code, fields}, errors:[...]}` with the status.
2. **RAISE with the public marker** (no result envelope, works inside `EXCEPTION WHEN OTHERS` re-wraps too):
   ```sql
   RAISE EXCEPTION '[public:insufficient_quantity:409] Insufficient quantity in selected warehouse';
   ```
   `[public]`, `[public:code]` and `[public:code:status]` are accepted. The text after the marker (up to a ` | ` or
   ` (SQLSTATE` suffix) is the public message.

Anything NOT marked public is generic. A platform can also classify centrally with
`DbErrorMapper::extend(fn (string $lowerCause, ?string $noun, string $rawCause, \Throwable $e): array|PublicError|null)`:
return `[status, 'message']` or a full `PublicError(status, message, data, errors, errorCode)` (dynamic data such as an
order number, a structured `errors` list). In a route-level `catch (PersistenceException $e)` use
`DbErrorMapper::publicResponse($status, $message, $data, $errors, $errorCode)` for context only the route knows.

### MIGRATION: replacing a hand-rolled write layer

| typical local code | framework |
|---|---|
| a local `mutate($fn, $params, allowEmpty, notFoundMessage)` helper | `Database::mutate(...)` (same argument order; extra `exactRows`, `noun`) |
| a local persistence exception | `StoneScriptPHP\Persistence\PersistenceException` |
| a local error-to-response mapper | `StoneScriptPHP\Persistence\DbErrorMapper::toResponse` (logs the sanitised chain, passes a coded `\RuntimeException` (4xx/5xx) through, upgrades a default-400 "not found" to 404) |
| status-guard middleware | `PERSISTENCE_CONTRACT=enforced` |
| per-route `translateException()` substring tables (`stripos($msg, 'Insufficient quantity')` -> `res_error(friendly, 400)`) | either one `DbErrorMapper::extend()` classifier registered at boot, or `[public:code:status]` in the SQL function (preferred: the message lives next to the rule) |
| a route's dynamic 409 (message embeds the order number, `errors` array) | route `catch (PersistenceException $e)` -> `DbErrorMapper::causeContains($e, 'orders_order_number_key') ? DbErrorMapper::publicResponse(409, "Order number ... is already used", null, [[ 'field' => 'order_number', 'message' => '...' ]]) : DbErrorMapper::toResponse($e, 'order')` |

`tests/Unit/PersistenceBusinessErrorParityTest.php` proves each such translation (expired voucher, insufficient quantity, missing
ids, empty order, variant not found, quantity, cap, duplicate number with dynamic text and field errors, unrecognised -> generic 500)
yields the identical status and message through both mechanisms, with no raw text in the response. Delete a local copy only
while an equivalent test of your own cases passes against the framework.

`allowEmpty: true` is only for a genuinely idempotent no-op (releasing zero reservations); an error envelope
still throws. Envelopes are recognised as direct columns, as one function-named JSON column, and with the
framework's `o_` prefix (`o_success`, `o_error`, `o_message`). A normal RETURNING row passes through untouched.

`mutate()` itself is the opt-in: it always throws on a failed write, in every contract mode.

Generated wrappers: put `-- @mutation` (or `-- @mutation allow-empty`, RETURNS TABLE functions only) in the SQL
file's leading comment block and `php stone generate model` emits `Database::mutateTyped(...)`.

## 2. `DbErrorMapper`: DB error -> status + safe message

`DbErrorMapper::classify($e, $noun)` / `toResponse($e, $noun)`. SQLSTATE is used when a PDO error carries one,
otherwise the gateway's cause text.

| cause | status |
|---|---|
| unique violation (23505) | 409 |
| foreign key violation (23503) | 400; 409 when deleting something still referenced |
| check violation (23514) | 422 |
| malformed value: bad uuid/int/date, too long, out of range (22xxx) | 400 |
| serialization failure, deadlock, lock timeout, cancelled (40001, 40P01, 55P03, 57014) | 503 |
| connection lost (gateway `connection_failed`, or a direct/pgandroid transport reporting a connection failure) | 503, retryable |
| anything else (undefined column, not-null, unrecognised RAISE, ...) | 500, generic message |

Messages never contain table, column or constraint names; the full error is logged (sanitised). An unrecognised error is
deliberately a 500, not a guessed 4xx. A platform's own business-rule vocabulary plugs in with
`DbErrorMapper::extend(fn (string $lowerCause, ?string $noun): ?array => ...)` (return `[status, safeMessage]`
or null), tried before the built-in table.

## 3. `ResponseGuard` + `PERSISTENCE_CONTRACT`

The router applies the guard to the final response of every request.

| | `lenient` (**default in 12.x**) | `enforced` (**default from the next major**) |
|---|---|---|
| error body (`error`/`not ok`) with no HTTP status | ships as 200 + a deprecation notice | 500 / 400 |
| 2xx after an unacknowledged failed `mutate()` | unchanged + notice | replaced by a 500 error |
| raw DB exception escaping a handler | 500 (+ notice when it would classify differently) | mapped (409/400/422/503) |
| `PersistenceException` escaping a handler | always its own status and message, in both modes | same |

A status that a middleware or handler already set is never overridden. The ledger holds at most 50 failures and is reset at the start and end of every dispatch; a long-lived worker (Swoole, RoadRunner, a queue consumer) that calls `mutate()` outside `dispatch()` should call `PersistenceLedger::reset()` between jobs. A handler that deliberately recovers
from a failed write (optional audit write, insert-then-update fallback) acknowledges it:

```php
try { Database::mutate('audit_log', [...]); }
catch (PersistenceException $e) { PersistenceLedger::handled($e); }
```

Configure: `PERSISTENCE_CONTRACT=lenient|enforced` (env / `.env` / `_FILE`), `persistence.contract` in the
`Application::run()` config, or `PersistenceContract::setMode()`. Adoption path: switch every write call to
`mutate()`, run on `lenient` and fix each deprecation notice, flip to `enforced`, delete platform-local copies.
