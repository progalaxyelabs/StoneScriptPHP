# Request Validation

StoneScriptPHP provides a powerful validation layer for validating incoming request data. The validation system uses a rules-based engine with built-in validators and support for custom validators.

## Table of Contents

- [Quick Start](#quick-start)
- [Available Rules](#available-rules)
- [Using Validation in Routes](#using-validation-in-routes)
- [Multiple Rules](#multiple-rules)
- [Custom Validators](#custom-validators)
- [Error Handling](#error-handling)
- [Advanced Usage](#advanced-usage)

## Quick Start

To add validation to a route, implement the `validation_rules()` method in your route handler:

```php
<?php

namespace App\Routes;

use Framework\ApiResponse;
use Framework\IRouteHandler;

class CreateUserRoute implements IRouteHandler
{
    public string $name;
    public string $email;
    public int $age;

    function validation_rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:50',
            'email' => 'required|email',
            'age' => 'required|integer|min:18'
        ];
    }

    function process(): ApiResponse
    {
        // Validation passes automatically before this is called
        // Create user with validated data
        return res_ok(['message' => 'User created successfully']);
    }
}
```

## Available Rules

### required

Validates that the field is present and not empty.

```php
'name' => 'required'
```

- Fails if field is missing, null, empty string, or whitespace-only
- Fails if field is an empty array

### email

Validates that the field is a valid email address.

```php
'email' => 'email'
```

- Uses PHP's `FILTER_VALIDATE_EMAIL` filter
- Passes if field is null (use `required` to enforce presence)

### min:value

Validates minimum length, value, or array count.

```php
'username' => 'min:3'      // Minimum 3 characters
'age' => 'min:18'          // Minimum value 18
'tags' => 'min:2'          // Minimum 2 array items
```

- For strings: validates minimum character length
- For numbers: validates minimum value
- For arrays: validates minimum item count

### max:value

Validates maximum length, value, or array count.

```php
'bio' => 'max:500'         // Maximum 500 characters
'score' => 'max:100'       // Maximum value 100
'items' => 'max:10'        // Maximum 10 array items
```

- For strings: validates maximum character length
- For numbers: validates maximum value
- For arrays: validates maximum item count

### numeric

Validates that the field is numeric.

```php
'amount' => 'numeric'
```

- Accepts integers, floats, and numeric strings
- Examples: `123`, `123.45`, `"123.45"`

### integer

Validates that the field is an integer.

```php
'count' => 'integer'
```

- Accepts integers and integer strings
- Examples: `123`, `"123"`
- Rejects floats: `123.45` (fails)

### string

Validates that the field is a string.

```php
'name' => 'string'
```

- Only accepts string values
- Rejects numbers, arrays, and other types

### array

Validates that the field is an array.

```php
'tags' => 'array'
```

- Only accepts array values

### boolean

Validates that the field is a boolean value.

```php
'active' => 'boolean'
```

- Accepts: `true`, `false`, `0`, `1`, `"0"`, `"1"`, `"true"`, `"false"`

### in:value1,value2,...

Validates that the field is one of the specified values.

```php
'status' => 'in:pending,active,inactive'
```

- Value must exactly match one of the listed options (strict comparison)

### url

Validates that the field is a valid URL.

```php
'website' => 'url'
```

- Uses PHP's `FILTER_VALIDATE_URL` filter
- Examples: `https://example.com`, `http://example.com/path?query=value`

### regex:pattern

Validates that the field matches a regular expression.

```php
'code' => 'regex:/^[A-Z]{3}-[0-9]{3}$/'
```

- Pattern must include delimiters (e.g., `/pattern/`)
- Example validates format: `ABC-123`

## Using Validation in Routes

### Basic Validation

```php
class LoginRoute implements IRouteHandler
{
    public string $email;
    public string $password;

    function validation_rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|min:8'
        ];
    }

    function process(): ApiResponse
    {
        // Validation has already passed
        // Implement login logic
        return res_ok(['token' => 'jwt-token-here']);
    }
}
```

### Optional Fields

Fields without the `required` rule are optional. Other rules only apply if the field is present:

```php
function validation_rules(): array
{
    return [
        'name' => 'required|string',
        'bio' => 'string|max:500',  // Optional, but must be string if provided
        'website' => 'url'           // Optional, but must be valid URL if provided
    ];
}
```

### No Validation Required

If no validation is needed, return an empty array:

```php
function validation_rules(): array
{
    return [];
}
```

## Multiple Rules

### Pipe-Separated Rules

```php
'email' => 'required|email|max:255'
```

### Array of Rules

```php
'email' => ['required', 'email', 'max:255']
```

Both formats are equivalent and can be used interchangeably.

## Custom Validators

You can create custom validators using the `addCustomValidator` method:

```php
use Framework\Validator;

$validator = new Validator($data, $rules);

// Add custom validator
$validator->addCustomValidator('alphanumeric', function($value, $parameter) {
    return ctype_alnum($value);
});

// Now you can use it in rules
$rules = [
    'username' => 'required|alphanumeric|min:3'
];
```

### Custom Validator with Parameters

```php
$validator->addCustomValidator('divisible_by', function($value, $parameter) {
    return is_numeric($value) && $value % $parameter === 0;
});

// Usage
$rules = [
    'quantity' => 'divisible_by:5'  // Must be divisible by 5
];
```

## Error Handling

### Automatic Error Responses

When validation fails, the framework automatically returns a 400 Bad Request response:

```json
{
  "status": "error",
  "message": "Validation failed",
  "data": {
    "email": ["The email must be a valid email address."],
    "age": ["The age must be at least 18."]
  }
}
```

In production mode (DEBUG_MODE = false), error details are hidden:

```json
{
  "status": "error",
  "message": "Validation failed"
}
```

### Manual Validation

You can also use the Validator class directly:

```php
use Framework\Validator;

$data = [
    'email' => 'user@example.com',
    'age' => 25
];

$rules = [
    'email' => 'required|email',
    'age' => 'required|integer|min:18'
];

$validator = new Validator($data, $rules);

if ($validator->validate()) {
    // Validation passed
    echo "Valid!";
} else {
    // Validation failed
    $errors = $validator->errors();
    print_r($errors);
}
```

### Accessing Errors

```php
// Get all errors grouped by field
$errors = $validator->errors();
// Returns: ['email' => ['error1', 'error2'], 'age' => ['error1']]

// Get first error message
$firstError = $validator->firstError();
// Returns: "The email must be a valid email address."

// Get all error messages as flat array
$messages = $validator->errorMessages();
// Returns: ['error1', 'error2', 'error3']
```

## Advanced Usage

### Static Factory Method

```php
$validator = Validator::make($data, $rules);

if (!$validator->validate()) {
    $errors = $validator->errors();
}
```

### Complex Validation Example

```php
class RegisterUserRoute implements IRouteHandler
{
    public string $username;
    public string $email;
    public string $password;
    public string $password_confirmation;
    public int $age;
    public string $country;
    public array $interests;
    public ?string $website;

    function validation_rules(): array
    {
        return [
            'username' => 'required|string|min:3|max:20',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8|max:128',
            'password_confirmation' => 'required|string',
            'age' => 'required|integer|min:13|max:120',
            'country' => 'required|string|regex:/^[A-Z]{2}$/',
            'interests' => 'required|array|min:1|max:10',
            'website' => 'url'  // Optional
        ];
    }

    function process(): ApiResponse
    {
        // Additional custom validation
        if ($this->password !== $this->password_confirmation) {
            return e400('Passwords do not match');
        }

        // Create user
        return res_ok(['message' => 'Registration successful']);
    }
}
```

### Validation with Database Checks

```php
class CreateProductRoute implements IRouteHandler
{
    public string $name;
    public string $sku;
    public float $price;

    function validation_rules(): array
    {
        return [
            'name' => 'required|string|min:3|max:100',
            'sku' => 'required|string|regex:/^[A-Z0-9-]+$/',
            'price' => 'required|numeric|min:0'
        ];
    }

    function process(): ApiResponse
    {
        // Validation rules passed, now check uniqueness
        $db = Database::get_instance();

        $existing = $db->query(
            'SELECT id FROM products WHERE sku = ?',
            [$this->sku]
        );

        if (!empty($existing)) {
            return e400('SKU already exists');
        }

        // Create product
        return res_ok(['message' => 'Product created']);
    }
}
```

## Best Practices

1. **Always validate required fields**: Use the `required` rule for all mandatory fields
2. **Set appropriate limits**: Use `min` and `max` to prevent abuse
3. **Validate types**: Use `string`, `integer`, `array`, etc. to ensure correct data types
4. **Use specific validators**: Prefer `email`, `url`, `integer` over generic `string` when applicable
5. **Keep validation simple**: Complex business logic should go in the `process()` method
6. **Return meaningful errors**: Let the framework's error messages guide users

## Error Messages

Default error messages are provided for all built-in validators:

- `required`: "The {field} field is required."
- `email`: "The {field} must be a valid email address."
- `min`: "The {field} must be at least {parameter}."
- `max`: "The {field} must not exceed {parameter}."
- `numeric`: "The {field} must be a number."
- `integer`: "The {field} must be an integer."
- `string`: "The {field} must be a string."
- `array`: "The {field} must be an array."
- `boolean`: "The {field} must be a boolean."
- `regex`: "The {field} format is invalid."
- `in`: "The {field} must be one of: {parameter}."
- `url`: "The {field} must be a valid URL."

Custom validators will show: "The {field} is invalid." unless you provide custom error handling.
