# StoneScriptPHP Framework Specification

This document defines the **prescriptive contract** for the StoneScriptPHP framework — the core library that powers all platform APIs. It describes what the framework is *meant* to do. Code that contradicts this spec should be fixed; this spec should not be reverse-engineered from code.

**Version:** 1.0.0 (April 2026)
**Framework version:** v3.20.x

---

## Table of Contents

1. [Overview & Scope](#1-overview--scope)
2. [Response Envelope](#2-response-envelope)
3. [Routing Conventions](#3-routing-conventions)
4. [DTO Layer](#4-dto-layer)
5. [PG Function Binding](#5-pg-function-binding)
6. [Auth/Session Model](#6-authsession-model)
7. [Stone CLI Commands](#7-stone-cli-commands)
8. [Client Generation Contract](#8-client-generation-contract)
9. [Versioning & Compatibility](#9-versioning--compatibility)
10. [Known Code/Spec Gaps](#10-known-codespec-gaps)

---

## 1. Overview & Scope

### What StoneScriptPHP Is

StoneScriptPHP is a **thin routing + DTO + PG-function-binding layer** for PHP APIs. It is:

- **PostgreSQL-first**: Business logic lives in database functions; PHP routes call them via the StoneScriptDB Gateway.
- **Gateway-only**: v3+ requires the StoneScriptDB Gateway; no direct PDO connections.
- **Code-generated**: CLI tools (`php stone generate`) produce route handlers, models, and TypeScript clients.
- **Convention-driven**: Follows prescriptive patterns for response format, parameter naming, and validation.

### What StoneScriptPHP Is NOT

- **Not a full-stack framework**: No ORM, no templating, no asset pipeline, no queue system.
- **Not database-agnostic**: PostgreSQL only, with specific PL/pgSQL conventions.
- **Not standalone**: Requires [stonescriptphp-server](https://github.com/progalaxyelabs/StoneScriptPHP-Server) skeleton for project setup.
- **Not auth-complete**: JWT validation is built-in; token issuance is delegated to external auth services (progalaxyelabs-auth).

### Related Packages

| Package | Purpose |
|---------|---------|
| **stonescriptphp** (this) | Core framework library |
| **stonescriptphp-server** | Application skeleton + CLI entry point |
| **stonescriptdb-gateway** | Rust database gateway (multi-tenant, connection pooling) |
| **stonescriptdb-gateway-client** | PHP client for gateway HTTP API |
| **ngx-stonescriptphp-client** | Angular HTTP client library |

---

## 2. Response Envelope

### Canonical Shape

Every API response — success or failure — MUST be wrapped in this envelope:

```json
{
  "status": "ok" | "not ok" | "error",
  "message": "Human-readable description",
  "data": null | {} | []
}
```

| Field | Type | Description |
|-------|------|-------------|
| `status` | `"ok"` \| `"not ok"` \| `"error"` | See status mapping below |
| `message` | string | Short, human-readable description |
| `data` | object, array, or null | Response payload |

### Status Field Mapping

| HTTP Range | `status` Value | When to Use |
|------------|----------------|-------------|
| 2xx | `"ok"` | Request succeeded |
| 4xx | `"not ok"` | Client error (validation, auth, not found) |
| 5xx | `"error"` | Server/framework error |

### Status Codes

| Code | Meaning | Framework Usage |
|------|---------|-----------------|
| 200 | OK | Default success |
| 201 | Created | Resource creation (optional; 200 is acceptable) |
| 204 | No Content | Empty success (rare; prefer 200 with `data: null`) |
| 400 | Bad Request | Malformed input, validation failure |
| 401 | Unauthorized | Missing/invalid/expired JWT |
| 403 | Forbidden | Authenticated but not authorized |
| 404 | Not Found | Route or resource not found |
| 405 | Method Not Allowed | HTTP method not supported for route |
| 422 | Unprocessable Entity | Semantic validation failure (e.g., business rule) |
| 429 | Rate Limit | Too many requests (if rate limiting enabled) |
| 500 | Server Error | Uncaught exception, framework error |
| 503 | Unavailable | Tenant database offline, gateway unreachable |

### Validation Error Response

Validation failures MUST return HTTP 400 with `status: "error"` and an optional `errors` array:

```json
{
  "status": "error",
  "message": "Validation failed",
  "data": {
    "email": ["The email field is required."],
    "password": ["The password must be at least 8 characters."]
  }
}
```

**Alternative format (top-level errors):**

```json
{
  "status": "error",
  "message": "Validation failed",
  "data": null,
  "errors": {
    "email": ["The email field is required."]
  }
}
```

Both formats are valid. The `errors` key may appear at the top level or nested in `data`.

### Helper Functions

Routes MUST use these helpers to construct responses:

```php
// Success responses
res_ok($data, $message = '')         // Returns ApiResponse with status="ok"

// Client error responses
res_not_ok($message, $httpStatusCode = 400)  // status="not ok"

// Server error responses
res_error($message, $httpStatusCode = 500)   // status="error", logs the error

// Shorthand error functions
e400($message = 'Bad Request')       // Returns 400 + res_error()
e404($message = 'Not found')         // Returns 404 + res_error()
e405($message = 'Method not allowed') // Returns 405 + res_error()
e500($message = 'Server error')      // Returns 500 + res_error()
```

### Content-Type

All responses MUST be `Content-Type: application/json`. The framework sets this automatically.

---

## 3. Routing Conventions

### Route Registration

Routes are defined in `src/config/routes.php`:

```php
return [
    'GET' => [
        '/health' => HealthRoute::class,
        '/users' => GetUsersRoute::class,
        '/users/{id}' => GetUserByIdRoute::class,
    ],
    'POST' => [
        '/users' => CreateUserRoute::class,
        '/auth/login' => LoginRoute::class,
    ],
    'PUT' => [
        '/users/{id}' => UpdateUserRoute::class,
    ],
    'DELETE' => [
        '/users/{id}' => DeleteUserRoute::class,
    ],
];
```

### Scoped Routes (New Format)

For multi-scope APIs (portal, admin, shared), use the array format:

```php
return [
    'scopes' => [
        'portal' => 'Customer-facing routes',
        'admin' => 'Admin panel routes',
        'shared' => 'Routes available to all scopes',
    ],
    'GET' => [
        '/health' => HealthRoute::class,  // Old format: defaults to 'shared' scope
        '/portal/dashboard' => [
            'handler' => GetDashboardRoute::class,
            'scope' => 'portal',
        ],
        '/admin/users' => [
            'handler' => AdminGetUsersRoute::class,
            'scope' => 'admin',
        ],
    ],
];
```

### Route Handler Interface

All route handlers MUST implement `IRouteHandler`:

```php
interface IRouteHandler
{
    public function validation_rules(): array;
    public function process(): ApiResponse;
}
```

### Route Handler Example

```php
class GetUserByIdRoute implements IRouteHandler
{
    // Public properties are auto-populated from request
    public int $id;

    public function validation_rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
        ];
    }

    public function process(): ApiResponse
    {
        $user = FnGetUserById::run($this->id);
        if (!$user) {
            return e404('User not found');
        }
        return res_ok(['user' => $user]);
    }
}
```

### URL Conventions

- Use **plural nouns** for collections: `/users`, `/products`
- Use **lowercase with hyphens**: `/user-profiles`, not `/userProfiles`
- **No verbs** in URLs: `/users` not `/get-users`
- Path parameters: `/users/{id}`, `/orders/{orderId}/items`
- Prefix by scope: `/portal/...`, `/admin/...`

### Request Input Extraction

| HTTP Method | Input Source | Content-Type Required |
|-------------|--------------|----------------------|
| GET | Query string (`$_GET`) | No |
| POST | JSON body | `application/json` (required) |
| PUT | JSON body | `application/json` (required) |
| PATCH | JSON body | `application/json` (required) |
| DELETE | Query string or JSON body | Optional |
| OPTIONS | None (CORS preflight) | No |

POST/PUT/PATCH requests without `Content-Type: application/json` MUST return HTTP 400.

---

## 4. DTO Layer

### Validation Rules

The `validation_rules()` method returns a map of field names to validation rules:

```php
public function validation_rules(): array
{
    return [
        'email' => 'required|email',
        'password' => 'required|string|min:8',
        'age' => 'integer|min:0|max:150',
        'role' => 'required|in:admin,user,guest',
        'tags' => 'array',
    ];
}
```

### Built-in Validators

| Rule | Description |
|------|-------------|
| `required` | Field must be present and non-empty |
| `email` | Valid email format |
| `string` | Must be a string |
| `integer` | Must be an integer |
| `numeric` | Must be numeric (int or float) |
| `boolean` | Must be boolean or truthy/falsy |
| `array` | Must be an array |
| `min:N` | Minimum length (string/array) or value (numeric) |
| `max:N` | Maximum length (string/array) or value (numeric) |
| `in:a,b,c` | Value must be one of the listed options |
| `regex:/pattern/` | Must match regex pattern |
| `url` | Valid URL format |

### Nullable vs Required

- Fields without `required` accept `null` or missing values
- Fields with `required` MUST be present and non-empty
- Empty string (`""`) fails `required`
- Empty array (`[]`) fails `required`

### Custom Validators

```php
$validator = new Validator($data, $rules);
$validator->addCustomValidator('phone', function ($value, $param) {
    return preg_match('/^[0-9]{10}$/', $value);
});
```

---

## 5. PG Function Binding

### Gateway Architecture

```
Route Handler → Database::fn() → GatewayClient → HTTP → StoneScriptDB Gateway → PostgreSQL
```

All database calls go through the StoneScriptDB Gateway. Direct PDO is forbidden in v3+.

### Parameter Naming Convention

PostgreSQL function parameters MUST be prefixed with `p_`:

```sql
CREATE OR REPLACE FUNCTION get_user_by_id(
    p_user_id INTEGER
)
RETURNS TABLE (id INTEGER, name VARCHAR, email VARCHAR)
AS $$ ... $$
LANGUAGE plpgsql;
```

The gateway strips `p_` prefix for gateway calls. PHP calls use unprefixed names:

```php
// PHP call
$user = Database::fn('get_user_by_id', ['user_id' => 42]);
```

### Tenant ID Injection

For multi-tenant functions, the gateway auto-injects `p_tenant_id` as the first parameter. Don't pass it from PHP:

```sql
-- SQL function
CREATE OR REPLACE FUNCTION list_items(
    p_tenant_id UUID,  -- Auto-injected by gateway
    p_page INTEGER,
    p_limit INTEGER
) RETURNS TABLE (...) AS $$ ... $$;
```

```php
// PHP call — tenant_id NOT passed
$items = Database::fn('list_items', ['page' => 1, 'limit' => 20]);
```

### Database Class Methods

```php
// Call a PostgreSQL function
Database::fn('function_name', ['param1' => $value1, 'param2' => $value2]): array

// Map result rows to typed objects
Database::result_as_single('fn_name', $rows, User::class): ?User
Database::result_as_table('fn_name', $rows, User::class): array

// Gateway client access (advanced)
Database::getGatewayClient(): GatewayClient
Database::isConnected(): bool
```

### Model Generation

Generate PHP model wrappers from SQL functions:

```bash
php stone generate model get_user_by_id.pgsql
```

Produces:

```php
class FnGetUserById
{
    public static function run(int $user_id): ?User
    {
        $rows = Database::fn('get_user_by_id', ['user_id' => $user_id]);
        return Database::result_as_single('get_user_by_id', $rows, User::class);
    }
}
```

### Exception Mapping

| PostgreSQL Exception | HTTP Status | `status` Value |
|---------------------|-------------|----------------|
| `RAISE EXCEPTION '...'` | 400 | `"error"` |
| Constraint violation | 409 | `"error"` |
| Function not found | 500 | `"error"` |
| Connection failed | 503 | `"error"` |

Business-rule exceptions from RAISE EXCEPTION include the message in the response:

```json
{
  "status": "error",
  "message": "Email already exists",
  "data": null
}
```

---

## 6. Auth/Session Model

### Framework Responsibilities

StoneScriptPHP provides:

- JWT validation middleware (`JwtAuthMiddleware`)
- JWKS-based public key fetching
- Auth context helpers (`auth()`, `auth_id()`, `auth_check()`)
- Multi-auth support (self-issued + external JWKS)

### Token Issuance

Token issuance is NOT handled by StoneScriptPHP. It is delegated to:

- **progalaxyelabs-auth**: Centralized auth service for all platforms
- **Self-contained mode**: Project generates own JWTs using RSA keys in `keys/`

### JWT Validation

Protected routes require `Authorization: Bearer <token>`. The framework validates:

1. Signature (RS256 via JWKS or local public key)
2. Expiration (`exp` claim)
3. Issuer (`iss` claim, if configured)

### Auth Context Helpers

```php
// Get authenticated user
$user = auth();                    // Returns AuthenticatedUser or null
$userId = auth_id();               // Returns user ID or null
$isLoggedIn = auth_check();        // Returns boolean

// Access claims
$tenantId = auth()->tenant_id;
$role = auth()->role;
$platformCode = auth()->platform_code;
```

### Tenant Context Helpers

```php
$tenant = tenant();                // Returns Tenant or null
$tenantId = tenant_id();           // UUID or null
$tenantSlug = tenant_slug();       // e.g., "acme"
$tenantDb = tenant_db_name();      // e.g., "medstoreapp_acme"
```

### Middleware Configuration

In `Application::run()`:

```php
Application::run([
    'auth' => [
        'mode' => 'external',       // 'external' or 'self'
        'jwks_url' => 'https://auth.progalaxyelabs.com/.well-known/jwks.json',
    ],
    'jwt' => [
        'excluded_paths' => ['/health', '/auth/login'],
    ],
]);
```

---

## 7. Stone CLI Commands

### Project Setup

```bash
php stone setup                    # Interactive project setup wizard
php stone env                      # Generate .env file
php stone generate jwt             # Generate RSA key pair for JWT signing
```

### Code Generation

```bash
php stone generate route <name>              # Generate route handler
php stone generate model <file.pgsql>        # Generate model from SQL function
php stone generate client                    # Generate TypeScript API client
php stone generate auth:email-password       # Email/password auth
php stone generate auth:google               # Google OAuth
php stone generate cache:redis               # Redis caching integration
```

### Database Migrations

```bash
php stone migrate verify                     # Check for schema drift
php stone migrate status                     # Show migration status
php stone gateway:register-main              # Register platform + create main DB
php stone gateway:register-tenant            # Upload tenant schema to gateway
php stone gateway:migrate-main               # Apply main DB migrations
php stone gateway:migrate-tenant --database-id=<uuid>  # Migrate one tenant
php stone gateway:migrate-all-tenants        # Migrate all tenant DBs
```

### Multi-Tenancy

```bash
php stone tenant:create "Acme Corp" acme     # Create tenant
php stone tenant:list                        # List all tenants
```

### Testing & Maintenance

```bash
php stone test                               # Run PHPUnit tests
php stone schema:export                      # Export schema as tar.gz
```

### Client Generation Options

```bash
php stone generate client                              # All routes
php stone generate client --scope=portal               # Only portal routes
php stone generate client --output=client/admin        # Custom output dir
php stone generate client --language=kotlin            # Kotlin instead of TypeScript
```

---

## 8. Client Generation Contract

### Generated Output

`php stone generate client` produces a TypeScript package in `client/`:

```
client/
  package.json          # npm package metadata
  tsconfig.json         # TypeScript config
  src/
    index.ts            # Exports all types and functions
  dist/                 # Compiled JavaScript
```

### Type Generation

Each route handler produces:

```typescript
// Request type (from public properties)
export interface CreateUserRequest {
  email: string;
  password: string;
  display_name?: string | null;
}

// Response type (from process() return shape)
export interface CreateUserResponse {
  user: User;
}
```

### API Client Shape

Generated functions use `ngx-stonescriptphp-client` for HTTP:

```typescript
import { ApiConnectionService, ApiResponse } from '@progalaxyelabs/ngx-stonescriptphp-client';

// Generated function
export function createUser(
  api: ApiConnectionService,
  request: CreateUserRequest
): Observable<ApiResponse<CreateUserResponse>> {
  return api.post('/users', request);
}
```

### Unresolved Types

Types that cannot be resolved become `Record<string, any>`:

```typescript
// Class not found during generation
export type AuthUser = Record<string, any>;
```

### Scope Filtering

With `--scope=portal`:

- Includes routes with `scope: 'portal'`
- Includes routes with `scope: 'shared'`
- Excludes routes with `scope: 'admin'`

### Integration

Angular services import from the generated client:

```typescript
import { CreateUserRequest, createUser } from '@stonescript/api-client';
```

---

## 9. Versioning & Compatibility

### Semver Policy

StoneScriptPHP follows [Semantic Versioning](https://semver.org/):

| Version Type | When to Use | Upgrade Safety |
|--------------|-------------|----------------|
| Patch (3.20.x) | Bug fixes, security patches | Safe, always upgrade |
| Minor (3.x.0) | New features, backward-compatible | Safe with testing |
| Major (x.0.0) | Breaking changes | Review migration guide |

### Breaking Changes (Major Version)

- Response envelope shape changes
- Removing/renaming public helper functions
- Changing validation rule behavior
- Changing parameter naming conventions
- Gateway client API changes

### Non-Breaking Changes (Minor Version)

- Adding new helper functions
- Adding new validation rules
- Adding new middleware
- Adding CLI commands
- Performance improvements

### Deprecation Policy

1. Deprecated features are logged with `@deprecated` PHPDoc
2. Deprecation warnings in CLI output
3. Minimum 1 minor version before removal
4. Removal only in major versions

---

## 10. Known Code/Spec Gaps

### Gap 1: Inconsistent Status Values in Error Functions

**Spec says:** `res_error()` should set `status: "error"`, `res_not_ok()` should set `status: "not ok"`.

**Code does:** Error helper functions (`e400`, `e404`, `e405`, `e500`) all use `res_error()`, which sets `status: "error"` even for 4xx client errors.

**File:** `src/error_handler.php:17-24`

**Recommendation:** 4xx errors should return `status: "not ok"`. Create `e401()`, `e403()`, `e409()`, `e422()` using `res_not_ok()`.

---

### Gap 2: Validation Errors in Data vs Top-Level

**Spec says:** Either `data` contains field errors OR `errors` is a top-level key.

**Code does:** `ApiResponse::toJson()` adds `errors` at top level (line 30), but `Router.php` puts validation errors in `data` (line 110-115).

**Files:**
- `src/ApiResponse.php:22-35`
- `src/Router.php:105-116`

**Recommendation:** Standardize on top-level `errors` key for field-level validation errors.

---

### Gap 3: Missing HTTP Status Helper Functions

**Spec says:** All common HTTP statuses should have helper functions.

**Code provides:** `e400`, `e404`, `e405`, `e500` only.

**Missing:** `e401`, `e403`, `e409`, `e422`, `e429`, `e503`

**File:** `src/error_handler.php` (incomplete)

**Recommendation:** Add missing error helpers.

---

### Gap 4: Gateway Exception Mapping Not Complete

**Spec says:** Business-rule `RAISE EXCEPTION` should map to HTTP 422.

**Code does:** All gateway exceptions become HTTP 500 except `connection_failed` (503).

**File:** `src/Database.php:126-140`

**Recommendation:** Parse gateway error messages to distinguish:
- `unique_violation` → 409
- `check_violation` → 422
- Business rule text → 422

---

### Gap 5: Router Doesn't Support PUT/PATCH/DELETE

**Spec says:** All CRUD methods should be supported.

**Code does:** `Router.php` only has `GetRequestParser` and `PostRequestParser`. No `PutRequestParser`, `PatchRequestParser`, or `DeleteRequestParser`.

**File:** `src/Router.php:179-294` (missing cases)

**Recommendation:** Add request parsers for PUT, PATCH, DELETE methods.

---

### Gap 6: Content-Type Charset Rejection

**Spec says:** `Content-Type: application/json` is required.

**Code does:** Rejects `application/json; charset=utf-8` (common browser default).

**File:** `src/Router.php:228-232`

**Recommendation:** Accept `application/json` with any charset suffix.

---

### Gap 7: No Pagination Convention in Framework

**Spec says:** Paginated responses should follow a standard shape.

**Code does:** No framework-level pagination helper. Each route implements its own.

**Recommendation:** Add `PaginatedResponse` helper class:

```php
res_paginated($items, $page, $limit, $total, $key = 'items')
```

---

### Gap 8: IRequest/IResponse Interfaces Empty

**Spec says:** `BaseRoute` uses `IRequest` and `IResponse` interfaces.

**Code does:** Interfaces are empty stubs (single line each).

**Files:**
- `src/IRequest.php` (empty interface)
- `src/IResponse.php` (empty interface)

**Recommendation:** Either remove these interfaces or implement typed request/response objects.

---

*End of specification. Last updated: April 2026.*
