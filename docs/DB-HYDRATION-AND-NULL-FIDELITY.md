# Database row hydration: NULL fidelity and money exactness

`Database::result_as_object()`, `result_as_single()`, `result_as_table()`, `result_as_typed_table()` and
`array_to_class_object()` all map a result row onto a model class through
`StoneScriptPHP\Database\RowHydrator`.

## The problem (legacy behaviour)

Generated model properties are non-nullable (a SQL function signature cannot say a returned column may be
NULL). When the function returns NULL for such a property the legacy mapper invented a value:

| property type | NULL became |
|---|---|
| `string` | `''` |
| `int` | `0` |
| `bool` | `false` |
| `float` | `''` -> `TypeError` |
| `?T` / `mixed` | `null` (always correct) |

`''` and `0` are indistinguishable from real data, so platforms grew shims (numeric coercion helpers,
`'' === $x` checks) to guess which it meant.

## Modes

Selected by `DB_HYDRATION_MODE` (env / `.env` / `_FILE` secret), or `db.hydration_mode` in the
`Application::run()` config, or `RowHydrator::setMode()` in code.

| | `legacy` (**default in 12.x**) | `strict` (**default from the next major**) |
|---|---|---|
| NULL -> nullable property | `null` | `null` |
| NULL -> non-nullable property | `''` / `0` / `false` + one `E_USER_DEPRECATED` per class::property per process | `HydrationException` naming function + property |
| wire value -> declared type | PHP weak-mode coercion (unchanged) | explicit conversion; a value that does not fit throws |
| bool | `true` / `'t'` | `true`/`false`, `'t'/'f'`, `'true'/'false'`, `'1'/'0'`, `1/0` |
| backed enum / `DateTimeImmutable` / `array` from JSON text | not supported | supported |
| JSON float into a `string` property | assigned (becomes `"1200.5"`, scale lost) | **throws** (money cannot drift silently) |
| `timestamp` (no zone) into a DateTime property | parsed in the PHP default timezone | parsed as **UTC**, independent of `date_default_timezone_set()`; `timestamptz` / offset values keep their own offset. Prefer `timestamptz` columns |

Unsupported property types (untyped, union, intersection) throw `HydrationException` in both modes (the old code
tried to `throw` a string, which was a fatal `Error`).

### Migration path

1. Upgrade; nothing changes except deprecation notices naming every `Class::$property` that received NULL.
2. (Test suites using PHPUnit `failOnDeprecation` will flag every legacy NULL hit; that is the migration worklist.)
   Fix each one: declare the property nullable. For generated models add an annotation in the leading comment
   block of the SQL file and regenerate:
   ```sql
   -- @out o_note ?string
   -- @out o_amount string
   CREATE OR REPLACE FUNCTION get_invoice(...) RETURNS TABLE (...)
   ```
   `<column>` may be written with or without `o_`. `@out` takes `[?]int|float|string|bool|array|mixed`.
3. Set `DB_HYDRATION_MODE=strict` (ideally in CI/staging first). No notices left = safe to flip.
4. Delete platform-side NULL/`''` guards and numeric coercion helpers.

## Money / NUMERIC exactness

A PHP `float` cannot hold every decimal exactly, and a value that has gone through a JSON number has already
been through a float, so exactness is a property of the DATA LAYER, not of this class. The exact path is:

1. The data layer delivers NUMERIC as text (the gateway's NUMERIC-as-string option). JSON numbers are lossy
   before the framework ever sees them; the hydrator cannot repair that.
2. Declare monetary columns as **`string`** (`-- @out o_amount string`). `RowHydrator` never routes a
   `string` property through `float`: `'12345678901234567.1250'` arrives byte for byte. In strict mode a JSON
   float arriving for a `string` property throws instead of being stringified, so a deployment that forgot step 1
   fails loudly rather than drifting.
3. Do arithmetic with bcmath (or integer minor units), never `float`.

`float` is fine for display-only quantities (percentages, ratios). A `float` property fed a numeric string is
converted with `(float)` and is therefore only as exact as an IEEE double.
