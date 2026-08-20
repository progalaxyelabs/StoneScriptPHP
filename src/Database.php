<?php

namespace StoneScriptPHP;

use DateTime;
use Error;
use Exception;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionProperty;
use ReflectionUnionType;
use StoneScriptDB\GatewayClient;
use StoneScriptDB\GatewayException;
use StoneScriptPHP\Db\DbTransport;
use StoneScriptPHP\Db\DbTransportException;
use StoneScriptPHP\Db\DirectTransport;
use StoneScriptPHP\Db\GatewayTransport;
use StoneScriptPHP\Db\PgandroidTransport;
use StoneScriptPHP\Binding\TypedArray;
use Throwable;

class Database
{
    private static ?Database $_instance = null;

    /**
     * The active database transport, resolved once per process from
     * Env::$DB_MODE (gateway | direct | pgandroid — default gateway). Every
     * Database::fn() call dispatches through this. See
     * src/Db/DbTransport.php and initConnection() below.
     */
    private ?DbTransport $transport = null;

    /**
     * Fake-mode response registry. Null = live gateway mode (default,
     * production behavior, untouched). Non-null = Database::fake() is
     * active; Database::fn() resolves from this map instead of calling a
     * real gateway. See TESTABILITY-SPEC.md T2-1.
     *
     * Declared `mixed` per-value, not `array|\Closure`, for the same reason
     * documented on fake()'s docblock — fake() is the sole writer and
     * already validates each value at registration time; keeping this
     * annotation loose avoids static analysis treating that validation as
     * unreachable.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $fakeResponses = null;

    private function __construct()
    {
        // Constructor no longer eagerly connects
        // Connection will be established lazily when first needed
    }

    /**
     * Resolve the active DbTransport from Env::$DB_MODE (gateway | direct |
     * pgandroid — default gateway). Resolved once and cached for the life
     * of the process, same as the old single-GatewayClient behavior.
     */
    private function initConnection(): void
    {
        if ($this->transport !== null) {
            return;
        }

        $env = Env::get_instance();

        // Env::__construct() already fails loud at boot on an unrecognized
        // DB_MODE (see Env.php) — this match() has no `default` arm on
        // purpose so a value that somehow slips past that check (e.g. a
        // hand-built Env test double) still fails loud here instead of
        // silently resolving to null.
        $this->transport = match ($env->DB_MODE) {
            'gateway' => $this->buildGatewayTransport($env),
            'direct' => $this->buildDirectTransport($env),
            'pgandroid' => new PgandroidTransport(),
            default => throw new Exception(
                "Unknown DB_MODE '{$env->DB_MODE}'. Must be one of: gateway, direct, pgandroid."
            ),
        };
    }

    /**
     * Build the gateway transport. Validation + GatewayClient construction
     * is UNCHANGED from the pre-transport-refactor initConnection() body —
     * byte-identical messages and behavior, since gateway is the default
     * transport every existing platform already depends on.
     */
    private function buildGatewayTransport(Env $env): GatewayTransport
    {
        // Validate gateway configuration
        if (empty($env->DB_GATEWAY_URL)) {
            throw new Exception('DB_GATEWAY_URL is required. StoneScriptPHP v3+ uses gateway-only mode. Run: php stone setup');
        }

        if (empty($env->DB_GATEWAY_PLATFORM)) {
            throw new Exception('DB_GATEWAY_PLATFORM is required. Run: php stone setup');
        }

        $start_time = microtime(true);

        if (empty($env->DB_GATEWAY_SCHEMA_NAME)) {
            throw new Exception(
                'DB_GATEWAY_SCHEMA_NAME is required. ' .
                'Add it to your .env file (e.g. DB_GATEWAY_SCHEMA_NAME=main).'
            );
        }

        $client = new GatewayClient(
            $env->DB_GATEWAY_URL,
            $env->DB_GATEWAY_PLATFORM,
            $env->DB_GATEWAY_SCHEMA_NAME,
            $env->DB_GATEWAY_TENANT_SCHEMA_NAME,
            $env->DB_GATEWAY_UUID ?? null
        );

        log_debug('Database: Gateway client initialized');

        $elapsed_time = microtime(true) - $start_time;
        log_debug(__METHOD__ . " Connection initialized in " . ($elapsed_time * 1000) . "ms");

        return new GatewayTransport($client);
    }

    /**
     * Build the direct (PDO) transport. DB_NAME is validated lazily here —
     * same pattern DB_GATEWAY_SCHEMA_NAME already uses for gateway mode —
     * rather than eagerly in Env, so booting with DB_MODE=direct doesn't
     * require every direct-mode var to be set until a Database::fn() call
     * actually happens.
     */
    private function buildDirectTransport(Env $env): DirectTransport
    {
        if (empty($env->DB_NAME)) {
            throw new Exception(
                'DB_NAME is required for DB_MODE=direct. ' .
                'Add it to your .env file (e.g. DB_NAME=myapp_main).'
            );
        }

        return new DirectTransport(
            $env->DB_HOST,
            $env->DB_PORT,
            $env->DB_NAME,
            $env->DB_USER,
            $env->DB_PASSWORD
        );
    }

    /**
     * Get the active transport, initializing the connection on first use.
     *
     * @return DbTransport
     */
    private function getTransport(): DbTransport
    {
        $this->initConnection();
        return $this->transport;
    }

    /**
     * Check if the active transport is initialized/connected.
     *
     * @return bool
     */
    public static function isConnected(): bool
    {
        if (self::$fakeResponses !== null) {
            // Nothing real to be "connected" to, but the fake is set up and
            // ready to serve Database::fn() calls — that's the meaningful
            // sense of "connected" for callers checking this in fake mode.
            return true;
        }

        $instance = self::get_instance();
        return $instance->getTransport()->isConnected();
    }

    /**
     * Get the gateway client for advanced operations (tenant-schema
     * routing via setTenantId(), provisioning). Only usable when
     * DB_MODE=gateway — direct/pgandroid have no equivalent concept.
     *
     * @return GatewayClient
     * @throws Exception If Database::fake() is active (no real client in
     *   fake mode), or if the active transport is not the gateway transport.
     */
    public static function getGatewayClient(): GatewayClient
    {
        if (self::$fakeResponses !== null) {
            throw new Exception(
                'Database::getGatewayClient() is not usable while Database::fake() is active — ' .
                'there is no real GatewayClient in fake mode. This method returns the live gateway ' .
                'client for tenant routing (GatewayTenantMiddleware); it has no fake-mode equivalent.'
            );
        }

        $instance = self::get_instance();
        $transport = $instance->getTransport();

        if (!$transport instanceof GatewayTransport) {
            throw new Exception(
                'Database::getGatewayClient() is only usable when DB_MODE=gateway (current transport: ' .
                get_class($transport) . '). Tenant-schema routing via setTenantId() and provisioning ' .
                '(createDatabase/migrateV2/...) are gateway-specific concepts with no direct/pgandroid equivalent.'
            );
        }

        return $transport->getClient();
    }

    /**
     * True when the active transport is the real gateway transport
     * (DB_MODE=gateway) and Database::fake() is not active. Lets
     * tenant-routing middleware (GatewayTenantMiddleware, StoreAccessMiddleware)
     * skip gateway-specific operations (setTenantId(), provisioning) on
     * DB_MODE=direct/pgandroid, where "route this request to a different
     * physical tenant database" has no equivalent — those transports talk to
     * exactly one database already (see getGatewayClient()'s docblock for
     * the same reasoning). Added 2026-08-01: found by the android-server
     * manual-build-v2 pass — the FIRST real API-token-authenticated request ever
     * driven through DB_MODE=pgandroid's full middleware pipeline hit
     * GatewayTenantMiddleware unconditionally calling getGatewayClient()
     * and throwing, because that middleware had no way to ask "should I
     * even try." DB_MODE=pgandroid was landed and unit-tested at the
     * Database::fn()/PgandroidTransport layer only — this is the first
     * proof it reached a full Application::run() request lifecycle with a
     * real, non-public route, which is exactly where a gateway-only
     * assumption elsewhere in the framework was always going to surface.
     *
     * @return bool
     */
    public static function isGatewayMode(): bool
    {
        if (self::$fakeResponses !== null) {
            return false;
        }

        $instance = self::get_instance();
        return $instance->getTransport() instanceof GatewayTransport;
    }

    /**
     * Enter fake mode: subsequent Database::fn() calls resolve from this
     * registry instead of calling a real gateway. Calls merge with any
     * previously registered responses (re-registering the same function
     * name overwrites just that key) — safe to call more than once per test
     * to layer base fixtures + test-specific overrides.
     *
     * Each value is either:
     *   - array    — canned rows, returned as-is on every call.
     *   - \Closure — (array $params): array, invoked fresh on every call.
     *                Use this for sequential/varying responses (maintain
     *                your own counter via `use (&$counter)`) or to simulate
     *                a gateway error by throwing a GatewayException — it
     *                gets the exact same translation _fn() already applies
     *                to real gateway errors (connection_failed →
     *                TenantDatabaseUnavailableException, everything else →
     *                wrapped Exception).
     *
     * Must be paired with Database::clearFakeMode() in tearDown() — fake
     * mode is process-global state, same discipline as AuthContext::clear().
     *
     * Declared as `array<string, mixed>` rather than `array<string,
     * array|\Closure>` deliberately: the per-value type is a documented
     * intent, not a real, PHP-enforced constraint (only the outer `array`
     * is a real type-hint) — a caller can still pass a string/int/null per
     * key, which is exactly what the runtime check below exists to catch.
     * A stricter docblock type here would make static analysis treat that
     * check as unreachable dead code, since it would trust the docblock as
     * a guarantee rather than the very thing being validated at runtime.
     *
     * @param array<string, mixed> $responses function_name => array $rows | \Closure(array $params): array
     * @throws Exception If a registered value is neither an array nor a Closure.
     */
    public static function fake(array $responses): void
    {
        foreach ($responses as $functionName => $response) {
            if (!is_array($response) && !($response instanceof \Closure)) {
                throw new Exception(
                    "Database::fake(): response for function '$functionName' must be an array of rows " .
                    'or a Closure (array $params): array, got ' . get_debug_type($response)
                );
            }
        }

        self::$fakeResponses ??= [];
        foreach ($responses as $functionName => $response) {
            self::$fakeResponses[$functionName] = $response;
        }
    }

    /**
     * Whether Database::fake() is currently active.
     *
     * @return bool
     */
    public static function isFaked(): bool
    {
        return self::$fakeResponses !== null;
    }

    /**
     * Turn off fake mode. Clears the fake response registry only — does not
     * touch the real singleton/GatewayClient (fake mode never populates it;
     * see _fn()), so there is nothing else to reset here. Call this in
     * tearDown() after using Database::fake().
     */
    public static function clearFakeMode(): void
    {
        self::$fakeResponses = null;
    }

    private static function get_instance(): Database
    {
        $start_time = microtime(true);

        if (!self::$_instance) {
            self::$_instance = new Database();
        }

        $elapsed_time = microtime(true) - $start_time;

        log_debug(__METHOD__ . " Timing: took $elapsed_time");

        return self::$_instance;
    }

    public static function fn(string $function_name, array $params): array
    {
        $start_time = microtime(true);
        $data = self::_fn($function_name, $params);
        $elapsed_time = microtime(true) - $start_time;

        log_debug(__METHOD__ . " Timing: $function_name took $elapsed_time");
        return $data;
    }

    private static function _fn(string $function_name, array $params): array
    {
        // Serialize typed/object params to their PG-wire (JSON text) form
        // BEFORE anything else, so both the real transport and fake mode see
        // identical, already-marshalled params — and so call sites stop
        // hand-rolling json_encode(...) before Database::fn(). See
        // serializeParams() for exactly what is (and isn't) touched.
        $params = self::serializeParams($params);

        try {
            // Fake mode check lives INSIDE this try block deliberately: a fake
            // Closure that throws a GatewayException (to simulate a business-rule
            // failure or a dropped tenant DB) gets the exact same translation
            // below that a real gateway error would — no parallel error-handling
            // path to keep in sync. See TESTABILITY-SPEC.md T2-1.
            if (self::$fakeResponses !== null) {
                return self::resolveFakeResponse($function_name, $params);
            }

            $transport = self::get_instance()->getTransport();
            return $transport->callFunction($function_name, $params);
        } catch (GatewayException $e) {
            log_debug(__METHOD__ . " Gateway error: " . $e->getMessage());

            // connection_failed means the tenant DB is unreachable (dropped, deprovisioned, or temporarily unavailable).
            // Throw a typed exception so the Router returns 503 instead of leaking a 500 to the client.
            if ($e->getGatewayError() === 'connection_failed') {
                throw new TenantDatabaseUnavailableException(
                    "Tenant database unavailable: " . $e->getMessage(),
                    503,
                    $e
                );
            }

            throw new Exception("Database function call failed: " . $e->getMessage(), $e->getCode(), $e);
        } catch (DbTransportException $e) {
            // Transport-agnostic equivalent of the GatewayException handling
            // above — DB_MODE=direct/pgandroid get the exact same
            // "connection failure -> 503, else -> wrapped 500" translation
            // gateway mode already has. See DbTransportException's docblock.
            log_debug(__METHOD__ . " Transport error: " . $e->getMessage());

            if ($e->isConnectionFailure()) {
                throw new TenantDatabaseUnavailableException(
                    "Tenant database unavailable: " . $e->getMessage(),
                    503,
                    $e
                );
            }

            throw new Exception("Database function call failed: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Marshal each Database::fn() parameter to its PostgreSQL-wire form.
     *
     * The typed-request work introduced {@see TypedArray} and readonly DTOs
     * as first-class values a route can hold. This lets a call site pass one
     * of those straight into Database::fn() — e.g.
     * `Database::fn('create_vendor_invoice', [$invoiceDto, $processStock])` —
     * instead of hand-writing `json_encode(...)` at every call site (the
     * pattern that let a field be mis-shaped or mis-cast per route). The DB
     * boundary now owns that conversion in ONE place.
     *
     * Deliberately surgical — this is purely ADDITIVE and cannot change the
     * wire bytes of any call that works today:
     *   - `null` and scalars (int/float/bool/string) pass straight through —
     *     including the JSON strings call sites already build by hand.
     *   - {@see TypedArray}, {@see \JsonSerializable} DTOs, and plain objects
     *     are `json_encode()`d to text (a PG json/jsonb param receives it,
     *     PG casts text→json exactly as before).
     *   - a plain PHP `array` is left UNTOUCHED — existing raw-array params
     *     keep whatever handling the transport/gateway gives them today; the
     *     typed path is opt-in via objects, so we never risk double-encoding.
     *
     * @param array<int|string, mixed> $params
     * @return array<int|string, mixed>
     */
    private static function serializeParams(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $out[$key] = self::serializeParam($value);
        }
        return $out;
    }

    private static function serializeParam(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof TypedArray || $value instanceof \JsonSerializable || is_object($value)) {
            $json = json_encode($value);
            if ($json === false) {
                throw new Exception(
                    'Database::fn(): failed to JSON-encode a ' . get_debug_type($value) .
                    ' parameter: ' . json_last_error_msg()
                );
            }
            return $json;
        }

        return $value;
    }

    /**
     * Resolve a Database::fn() call against the fake response registry.
     * Only called when Database::isFaked() is true.
     *
     * @throws Exception If no fake response is registered for $function_name —
     *   deliberately loud rather than silently falling through to a real
     *   gateway call or returning an empty result, so a test author is never
     *   left wondering whether an unstubbed call quietly hit the network.
     */
    private static function resolveFakeResponse(string $function_name, array $params): array
    {
        if (!array_key_exists($function_name, self::$fakeResponses)) {
            throw new Exception(
                "Database::fake() is active but no fake response is registered for function " .
                "'$function_name'. Register one via Database::fake(['$function_name' => [...]]) " .
                'or call Database::clearFakeMode() to return to live-gateway mode.'
            );
        }

        $response = self::$fakeResponses[$function_name];

        return $response instanceof \Closure ? $response($params) : $response;
    }

    /**
     * Execute a raw SQL query (DEPRECATED - Gateway mode only).
     *
     * @deprecated StoneScriptPHP v3+ uses gateway-only mode. Use PostgreSQL functions instead.
     * @param string $sql The SQL query to execute
     * @return array The result rows
     * @throws Exception Always throws in gateway mode
     */
    public static function internal_query($sql): array
    {
        throw new Exception(
            'internal_query() is not available in gateway mode. ' .
            'StoneScriptPHP v3+ uses gateway-only architecture. ' .
            'Please create PostgreSQL functions in src/postgresql/functions/ instead.'
        );
    }

    public static function result_as_object(string $function_name, array $rows, string $class)
    {
        if (empty($rows)) {
            return null;
        }

        return self::array_to_class_object($function_name, $rows[0], $class, true);
    }

    /**
     * Convert PostgreSQL function result to a single model object.
     * Use this for functions that return exactly one row (get by ID, create, update).
     *
     * @param string $function_name Name of the database function (for error context)
     * @param array $rows Result rows from Database::fn()
     * @param string $class Fully qualified class name for mapping
     * @return object|null Single instance of $class or null if no rows
     */
    public static function result_as_single(string $function_name, array $rows, string $class): ?object
    {
        if (empty($rows)) {
            return null;
        }

        return self::array_to_class_object($function_name, $rows[0], $class);
    }

    public static function result_as_table(string $function_name, array $rows, string $class): array
    {
        $data = [];
        foreach ($rows as $row) {
            $data[] = self::array_to_class_object($function_name, $row, $class);
        }

        return $data;
    }

    /**
     * Deserialize a multi-row PostgreSQL function result into a typed
     * collection — the OUT side of the same "Database owns the marshalling"
     * boundary that {@see serializeParams()} owns on the IN side.
     *
     * Identical row-to-model mapping as {@see result_as_table()}, but returns
     * a {@see TypedArray} of $class instead of a bare `array`, so the result
     * carries its element type: every element is guaranteed a $class instance,
     * it iterates typed, and it serializes straight back to a JSON list
     * through `res_ok()`. Generated `Fn*::run()` return-table wrappers should
     * target this; existing callers of result_as_table() are untouched.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param class-string $class
     * @return TypedArray<object>
     */
    public static function result_as_typed_table(string $function_name, array $rows, string $class): TypedArray
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::array_to_class_object($function_name, $row, $class);
        }

        return new TypedArray($class, $items);
    }

    public static function array_to_class_object(string $function_name, array $row, string $class, bool $as_out_param = false): object
    {
        $instance = new $class();
        $reflect = new ReflectionClass($instance);
        $properties   = $reflect->getProperties(ReflectionProperty::IS_PUBLIC);
        $missing_properties = [];

        foreach ($properties as $property) {
            $p_name = $property->getName();
            $reflect_type = $property->getType();

            if (
                ($reflect_type === null)
                || ($reflect_type instanceof ReflectionUnionType)
                || (($reflect_type instanceof ReflectionIntersectionType)
                )
            ) {
                throw "Unsupported type for property [$p_name]";
            }

            $p_type = $reflect_type->getName();
            $p_nullable = $reflect_type->allowsNull();

            log_debug(__METHOD__ . " property [$p_name] type is [$p_type]" .  ($p_nullable ? " and allows null" : ""));

            // log_debug(__METHOD__ . ' ' . var_export($row, true));

            // Resolve the result-row key for this property (SPEC §5, Output Column
            // Naming). Model properties are canonically UNPREFIXED; PostgreSQL
            // functions emit `o_`-prefixed output columns (OUT-param / RETURNS TABLE
            // convention, to avoid clashing with table columns inside the function
            // body). Match the exact property name first, then fall back to the
            // `o_`-prefixed key. This is consistent across all result mappers and
            // handles every case without re-breaking on model regeneration:
            //   - clean prop `id`        + row `o_id`  → o_ fallback
            //   - clean prop `id`        + row `id`    → exact (legacy unprefixed fns)
            //   - hand-fixed prop `o_id` + row `o_id`  → exact
            // The legacy `$as_out_param` flag is retained for signature back-compat
            // but no longer drives resolution (it previously forced an o_-prepend-only
            // match, which would seek `o_o_id` for an already-`o_` property).
            $prefixed_key = 'o_' . $p_name;
            if (array_key_exists($p_name, $row)) {
                $row_key = $p_name;
            } elseif (array_key_exists($prefixed_key, $row)) {
                $row_key = $prefixed_key;
            } else {
                $row_key = null;
            }

            if ($row_key !== null) {
                if ($row[$row_key] === null) {
                    if ($p_nullable) {
                        $instance->$p_name = null;
                    } else if ($p_type === 'int') {
                        $instance->$p_name = 0;
                    } else if ($p_type === 'bool') {
                        $instance->$p_name = false;
                    } else {
                        $instance->$p_name = '';
                    }
                } else if ($p_type === 'DateTime') {
                    $instance->$p_name = new DateTime($row[$row_key]);
                } else if ($p_type === 'bool') {
                    // Handle BOTH transport modes: StoneScriptDB Gateway mode
                    // decodes responses via json_decode() so JSON booleans arrive
                    // as native PHP `true`/`false`; libpq text mode delivers the
                    // string 't'/'f'. Strict `===` keeps int/string 0/1 from
                    // leaking through. (NULL is handled by the branch above.)
                    $instance->$p_name = ($row[$row_key] === true || $row[$row_key] === 't');
                } else {
                    $instance->$p_name = $row[$row_key];
                }
            } else {
                log_debug(" expected [$p_name] or [$prefixed_key] with type [$p_type] from class [$class] but neither found in db function [$function_name] result");
                $missing_properties[] = $p_name;
            }
        }

        if (count($missing_properties) > 0) {
            throw new Exception("mismatch in function result fields and class properties");
        }

        return $instance;
    }

}
