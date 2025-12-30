# Handoff: Route/Service Separation in Code Generator

## Summary

Update `php stone generate route` to generate separate Route and Service classes, enabling testable business logic without HTTP dependencies.

## Current Behavior

Running `php stone generate route post /admin` creates:

```
src/App/
├── Routes/PostAdminRoute.php      # Has both HTTP handling AND business logic
├── Contracts/IPostAdminRoute.php  # Interface with execute() method
├── DTO/PostAdminRequest.php
└── DTO/PostAdminResponse.php
```

**Problem:** The `execute()` method containing business logic is inside the Route class, making it difficult to test without HTTP/framework dependencies.

## Desired Behavior

Running `php stone generate route post /admin` should create:

```
src/App/
├── Routes/PostAdminRoute.php         # HTTP handling ONLY
├── Services/PostAdminService.php     # Business logic ONLY
├── Contracts/IPostAdminService.php   # Interface (renamed from IPostAdminRoute)
├── DTO/PostAdminRequest.php
└── DTO/PostAdminResponse.php
```

## File Templates

### 1. Route Class (HTTP handling only)

```php
<?php

namespace App\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use App\Services\PostAdminService;
use App\DTO\PostAdminRequest;

class PostAdminRoute implements IRouteHandler
{
    // Path parameters (auto-generated from route path)
    // public string $id;

    // Request body/query parameters
    public ?string $email = null;
    public ?string $name = null;

    public function validation_rules(): array
    {
        return [
            // TODO: Add validation rules
            // 'email' => 'required|email',
            // 'name' => 'required|min:2|max:80',
        ];
    }

    public function process(): ApiResponse
    {
        $request = new PostAdminRequest(
            // TODO: Map properties from validated input
            // email: $this->email,
            // name: $this->name,
        );

        $service = new PostAdminService();
        $response = $service->execute($request);

        return res_ok($response);
    }
}
```

### 2. Service Class (Business logic only)

```php
<?php

namespace App\Services;

use App\Contracts\IPostAdminService;
use App\DTO\PostAdminRequest;
use App\DTO\PostAdminResponse;

class PostAdminService implements IPostAdminService
{
    public function execute(PostAdminRequest $request): PostAdminResponse
    {
        // TODO: Implement business logic
        // This method receives validated input and should contain
        // pure business logic with no HTTP dependencies.
        //
        // Example:
        // $result = FnCreateAdmin::run($request->email, $request->name);
        // return new PostAdminResponse(admin_id: $result[0]->o_admin_id);

        throw new \Exception('Not implemented');
    }
}
```

### 3. Interface (for testability)

```php
<?php

namespace App\Contracts;

use App\DTO\PostAdminRequest;
use App\DTO\PostAdminResponse;

interface IPostAdminService
{
    public function execute(PostAdminRequest $request): PostAdminResponse;
}
```

### 4. Request DTO (unchanged)

```php
<?php

namespace App\DTO;

class PostAdminRequest
{
    public function __construct(
        // TODO: Add request properties
        // public readonly string $email,
        // public readonly string $name,
    ) {}
}
```

### 5. Response DTO (unchanged)

```php
<?php

namespace App\DTO;

class PostAdminResponse
{
    public function __construct(
        // TODO: Add response properties
        // public readonly int $admin_id,
        // public readonly ?string $error = null,
    ) {}
}
```

## Responsibility Separation

| Layer | Responsibilities |
|-------|------------------|
| **Framework Router** | Parse HTTP request, match route, instantiate Route class |
| **Route Class** | Define validation rules, map input to DTO, call service, format HTTP response |
| **Service Class** | Pure business logic, database calls via Fn* wrappers, domain exceptions |
| **Interface** | Contract for service, enables mocking in tests |

## Validation Flow

```
HTTP Request
    ↓
Framework validates against validation_rules()
    ↓ (400 Bad Request if invalid)
Route::process() called with validated input
    ↓
Route maps input to Request DTO
    ↓
Service::execute() called with DTO
    ↓
Service returns Response DTO
    ↓
Route returns res_ok(response)
```

## Testing Benefits

```php
// Unit test for service - no HTTP, no framework
class PostAdminServiceTest extends TestCase
{
    public function test_creates_admin_successfully(): void
    {
        $request = new PostAdminRequest(
            email: 'admin@example.com',
            name: 'Test Admin'
        );

        $service = new PostAdminService();
        $response = $service->execute($request);

        $this->assertNotNull($response->admin_id);
    }
}
```

## Files to Modify

1. **`cli/generate-route.php`** - Update templates and add Service class generation
2. Create `src/App/Services/` directory if it doesn't exist

## Migration Note

Existing routes using the old pattern (with `execute()` in Route class) will continue to work. This change only affects newly generated routes. Consider adding a flag `--legacy` to generate the old pattern if needed for consistency in existing projects.

---

# Part 2: BaseRoute Abstract Class (Reduce Boilerplate)

## Problem

All route files follow the same pattern with redundant code:

1. Define validation rules
2. Map input to Request DTO
3. Call service
4. Return `res_ok($response)`

The only differences are validation rules, property names, and DTO classes.

## Solution: Abstract Base Class

Add a `BaseRoute` abstract class to the framework that routes can extend, reducing boilerplate by ~50%.

### Framework Interfaces (add to StoneScriptPHP)

```php
<?php

namespace StoneScriptPHP;

/**
 * Marker interface for request DTOs.
 * All request DTOs must implement this.
 */
interface IRequest {}
```

```php
<?php

namespace StoneScriptPHP;

/**
 * Marker interface for response DTOs.
 * All response DTOs must implement this.
 */
interface IResponse {}
```

### BaseRoute Abstract Class (add to StoneScriptPHP)

```php
<?php

namespace StoneScriptPHP;

abstract class BaseRoute implements IRouteHandler
{
    /**
     * Define field-level validation rules.
     * @return array<string, string>
     */
    abstract public function validation_rules(): array;

    /**
     * Build request DTO from validated input properties.
     */
    abstract protected function buildRequest(): IRequest;

    /**
     * Execute business logic (delegate to service).
     */
    abstract protected function execute(IRequest $request): IResponse;

    /**
     * Cross-field validation after field-level rules pass.
     * Override this method when validation involves multiple fields.
     *
     * @return string|array<string,string>|null Error message(s) or null if valid
     */
    protected function custom_validation(): string|array|null
    {
        return null;
    }

    /**
     * Standard HTTP processing - do not override.
     */
    public function process(): ApiResponse
    {
        // Run cross-field validation
        $error = $this->custom_validation();
        if ($error !== null) {
            return res_error($error, 400);
        }

        $request = $this->buildRequest();
        $response = $this->execute($request);
        return res_ok($response);
    }
}
```

## Updated Route Template

Routes now extend `BaseRoute` instead of implementing `IRouteHandler` directly:

```php
<?php

namespace App\Routes;

use StoneScriptPHP\BaseRoute;
use StoneScriptPHP\IRequest;
use StoneScriptPHP\IResponse;
use App\Services\PostAdminService;
use App\DTO\PostAdminRequest;
use App\DTO\PostAdminResponse;

class PostAdminRoute extends BaseRoute
{
    public ?string $email = null;
    public ?string $name = null;

    public function validation_rules(): array
    {
        return [
            'email' => 'required|email',
            'name' => 'required|min:2|max:80',
        ];
    }

    protected function buildRequest(): IRequest
    {
        return new PostAdminRequest(
            email: $this->email ?? '',
            name: $this->name ?? '',
        );
    }

    protected function execute(IRequest $request): IResponse
    {
        return (new PostAdminService())->execute($request);
    }
}
```

## Updated DTO Templates

DTOs must implement the marker interfaces:

```php
<?php

namespace App\DTO;

use StoneScriptPHP\IRequest;

class PostAdminRequest implements IRequest
{
    public function __construct(
        public readonly string $email,
        public readonly string $name,
    ) {}
}
```

```php
<?php

namespace App\DTO;

use StoneScriptPHP\IResponse;

class PostAdminResponse implements IResponse
{
    public function __construct(
        public readonly int $admin_id,
        public readonly ?string $error = null,
    ) {}
}
```

## Cross-Field Validation Examples

### Example 1: Date range validation

```php
class PostOrderRoute extends BaseRoute
{
    public ?string $start_date = null;
    public ?string $end_date = null;

    public function validation_rules(): array
    {
        return [
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ];
    }

    protected function custom_validation(): string|array|null
    {
        if (strtotime($this->end_date) <= strtotime($this->start_date)) {
            return 'end_date must be after start_date';
        }
        return null;
    }

    // ... buildRequest(), execute()
}
```

### Example 2: Conditional validation with multiple errors

```php
class PostCheckoutRoute extends BaseRoute
{
    public ?float $total = null;
    public ?string $discount_code = null;
    public ?string $password = null;
    public ?string $confirm_password = null;

    public function validation_rules(): array
    {
        return [
            'total' => 'required|numeric|min:0',
            'discount_code' => 'string|max:20',
            'password' => 'required|min:8',
            'confirm_password' => 'required',
        ];
    }

    protected function custom_validation(): string|array|null
    {
        $errors = [];

        if ($this->password !== $this->confirm_password) {
            $errors['confirm_password'] = 'Passwords do not match';
        }

        if ($this->discount_code && $this->total < 100) {
            $errors['discount_code'] = 'Discount codes only valid for orders over 100';
        }

        return empty($errors) ? null : $errors;
    }

    // ... buildRequest(), execute()
}
```

## Performance Notes

- **No performance concerns** with this approach
- Uses standard PHP inheritance (no reflection at runtime)
- `custom_validation()` only called once per request
- If using attributes in future: OPcache caches reflection data, overhead is microseconds

## Why Interfaces Instead of `mixed`?

Using `IRequest` and `IResponse` interfaces instead of `mixed`:

| Approach | Type Safety | PHPStan | IDE Support |
|----------|-------------|---------|-------------|
| `mixed` | None | No errors | No autocomplete |
| Interfaces | Contract-based | Catches misuse | Full autocomplete |

PHPStan will ensure:
- `buildRequest()` returns something implementing `IRequest`
- `execute()` receives `IRequest` and returns `IResponse`
- Service classes work with correct types

## Checklist

- [ ] Update `cli/generate-route.php` to generate Service class
- [ ] Rename interface from `I{Name}Route` to `I{Name}Service`
- [ ] Remove `execute()` method from Route template
- [ ] Remove interface implementation from Route class
- [ ] Add Service instantiation in Route::process()
- [ ] Create `Services/` directory in generator
- [ ] Update "Next steps" output message
- [ ] Update help text/documentation
- [ ] Add `IRequest` interface to framework
- [ ] Add `IResponse` interface to framework
- [ ] Add `BaseRoute` abstract class to framework
- [ ] Update DTO templates to implement `IRequest`/`IResponse`
- [ ] Update route template to extend `BaseRoute`
- [ ] Add `custom_validation()` example to generated route comments
