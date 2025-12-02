<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Framework\Validator;

/**
 * Validator Unit Tests
 *
 * Tests the validation rules engine including:
 * - Built-in validation rules (required, email, min, max, etc.)
 * - Custom validators
 * - Error message handling
 * - Multiple rules per field
 */
class ValidatorTest extends TestCase
{
    /**
     * Test that required rule validates correctly
     */
    public function test_required_rule_validates_correctly(): void
    {
        // Test with missing field
        $data = [];
        $rules = ['name' => 'required'];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('name', $validator->errors());

        // Test with null value
        $data = ['name' => null];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with empty string
        $data = ['name' => ''];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with whitespace only
        $data = ['name' => '   '];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with valid value
        $data = ['name' => 'John'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
        $this->assertEmpty($validator->errors());
    }

    /**
     * Test that email rule validates correctly
     */
    public function test_email_rule_validates_correctly(): void
    {
        $rules = ['email' => 'email'];

        // Test with invalid email
        $data = ['email' => 'not-an-email'];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());
        $this->assertArrayHasKey('email', $validator->errors());

        // Test with valid email
        $data = ['email' => 'user@example.com'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());

        // Test with missing email (should pass - use required for presence check)
        $data = [];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    /**
     * Test that min rule validates correctly for strings
     */
    public function test_min_rule_validates_string_length(): void
    {
        $rules = ['username' => 'min:3'];

        // Test with string too short
        $data = ['username' => 'ab'];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with exact length
        $data = ['username' => 'abc'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());

        // Test with longer string
        $data = ['username' => 'abcdef'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    /**
     * Test that min rule validates correctly for numbers
     */
    public function test_min_rule_validates_numeric_values(): void
    {
        $rules = ['age' => 'min:18'];

        // Test with value too small
        $data = ['age' => 17];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with exact value
        $data = ['age' => 18];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());

        // Test with larger value
        $data = ['age' => 25];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    /**
     * Test that max rule validates correctly for strings
     */
    public function test_max_rule_validates_string_length(): void
    {
        $rules = ['bio' => 'max:100'];

        // Test with string too long
        $data = ['bio' => str_repeat('a', 101)];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with exact length
        $data = ['bio' => str_repeat('a', 100)];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());

        // Test with shorter string
        $data = ['bio' => str_repeat('a', 50)];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    /**
     * Test that max rule validates correctly for numbers
     */
    public function test_max_rule_validates_numeric_values(): void
    {
        $rules = ['score' => 'max:100'];

        // Test with value too large
        $data = ['score' => 101];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with exact value
        $data = ['score' => 100];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());

        // Test with smaller value
        $data = ['score' => 50];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    /**
     * Test that numeric rule validates correctly
     */
    public function test_numeric_rule_validates_correctly(): void
    {
        $rules = ['amount' => 'numeric'];

        // Test with non-numeric value
        $data = ['amount' => 'abc'];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with integer
        $data = ['amount' => 123];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());

        // Test with float
        $data = ['amount' => 123.45];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());

        // Test with numeric string
        $data = ['amount' => '123.45'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    /**
     * Test that integer rule validates correctly
     */
    public function test_integer_rule_validates_correctly(): void
    {
        $rules = ['count' => 'integer'];

        // Test with non-integer
        $data = ['count' => 'abc'];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with float
        $data = ['count' => 123.45];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with integer
        $data = ['count' => 123];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());

        // Test with integer string
        $data = ['count' => '123'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    /**
     * Test that string rule validates correctly
     */
    public function test_string_rule_validates_correctly(): void
    {
        $rules = ['name' => 'string'];

        // Test with non-string
        $data = ['name' => 123];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with string
        $data = ['name' => 'John Doe'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    /**
     * Test that array rule validates correctly
     */
    public function test_array_rule_validates_correctly(): void
    {
        $rules = ['tags' => 'array'];

        // Test with non-array
        $data = ['tags' => 'not an array'];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with array
        $data = ['tags' => ['php', 'javascript']];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    /**
     * Test that boolean rule validates correctly
     */
    public function test_boolean_rule_validates_correctly(): void
    {
        $rules = ['active' => 'boolean'];

        // Test with invalid boolean
        $data = ['active' => 'maybe'];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with boolean true
        $data = ['active' => true];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());

        // Test with boolean false
        $data = ['active' => false];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());

        // Test with integer 1
        $data = ['active' => 1];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());

        // Test with string 'true'
        $data = ['active' => 'true'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    /**
     * Test that in rule validates correctly
     */
    public function test_in_rule_validates_correctly(): void
    {
        $rules = ['status' => 'in:pending,active,inactive'];

        // Test with invalid value
        $data = ['status' => 'deleted'];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with valid values
        foreach (['pending', 'active', 'inactive'] as $status) {
            $data = ['status' => $status];
            $validator = new Validator($data, $rules);

            $this->assertTrue($validator->validate());
        }
    }

    /**
     * Test that url rule validates correctly
     */
    public function test_url_rule_validates_correctly(): void
    {
        $rules = ['website' => 'url'];

        // Test with invalid URL
        $data = ['website' => 'not a url'];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with valid URL
        $data = ['website' => 'https://example.com'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());

        // Test with another valid URL
        $data = ['website' => 'http://example.com/path?query=value'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    /**
     * Test that regex rule validates correctly
     */
    public function test_regex_rule_validates_correctly(): void
    {
        $rules = ['code' => 'regex:/^[A-Z]{3}-[0-9]{3}$/'];

        // Test with invalid format
        $data = ['code' => 'abc-123'];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with valid format
        $data = ['code' => 'ABC-123'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    /**
     * Test multiple rules on a single field
     */
    public function test_multiple_rules_on_single_field(): void
    {
        $rules = ['email' => 'required|email'];

        // Test with missing email
        $data = [];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with invalid email
        $data = ['email' => 'not-an-email'];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());

        // Test with valid email
        $data = ['email' => 'user@example.com'];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    /**
     * Test multiple fields with different rules
     */
    public function test_multiple_fields_with_different_rules(): void
    {
        $rules = [
            'name' => 'required|string|min:2|max:50',
            'email' => 'required|email',
            'age' => 'required|integer|min:18|max:120'
        ];

        // Test with all valid
        $data = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => 25
        ];
        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());

        // Test with multiple invalid fields
        $data = [
            'name' => 'J',  // Too short
            'email' => 'invalid',  // Invalid email
            'age' => 17  // Too young
        ];
        $validator = new Validator($data, $rules);

        $this->assertFalse($validator->validate());
        $errors = $validator->errors();

        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('age', $errors);
    }

    /**
     * Test custom validator registration and usage
     */
    public function test_custom_validator_works(): void
    {
        $rules = ['username' => 'custom_alphanum'];
        $data = ['username' => 'user123'];

        $validator = new Validator($data, $rules);

        // Register custom validator
        $validator->addCustomValidator('custom_alphanum', function($value, $parameter) {
            return ctype_alnum($value);
        });

        // Test with valid alphanumeric
        $this->assertTrue($validator->validate());

        // Test with invalid (contains special chars)
        $data = ['username' => 'user@123'];
        $validator = new Validator($data, $rules);
        $validator->addCustomValidator('custom_alphanum', function($value, $parameter) {
            return ctype_alnum($value);
        });

        $this->assertFalse($validator->validate());
    }

    /**
     * Test error messages retrieval
     */
    public function test_error_messages_retrieval(): void
    {
        $rules = [
            'name' => 'required|min:3',
            'email' => 'required|email'
        ];
        $data = [
            'name' => 'ab',
            'email' => 'invalid'
        ];

        $validator = new Validator($data, $rules);
        $validator->validate();

        // Test errors() method
        $errors = $validator->errors();
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);

        // Test firstError() method
        $firstError = $validator->firstError();
        $this->assertIsString($firstError);
        $this->assertNotEmpty($firstError);

        // Test errorMessages() method
        $messages = $validator->errorMessages();
        $this->assertIsArray($messages);
        $this->assertGreaterThan(0, count($messages));
    }

    /**
     * Test static make method
     */
    public function test_static_make_method(): void
    {
        $data = ['email' => 'user@example.com'];
        $rules = ['email' => 'required|email'];

        $validator = Validator::make($data, $rules);

        $this->assertInstanceOf(Validator::class, $validator);
        $this->assertTrue($validator->validate());
    }

    /**
     * Test that rules can be provided as array
     */
    public function test_rules_as_array(): void
    {
        $rules = [
            'email' => ['required', 'email']
        ];
        $data = ['email' => 'user@example.com'];

        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
    }

    /**
     * Test validation with empty rules
     */
    public function test_validation_with_empty_rules(): void
    {
        $data = ['name' => 'John'];
        $rules = [];

        $validator = new Validator($data, $rules);

        $this->assertTrue($validator->validate());
        $this->assertEmpty($validator->errors());
    }
}
