<?php

namespace StoneScriptPHP;

/**
 * Validator class for request validation
 *
 * Provides a rules-based validation engine for validating request data
 * against defined validation rules.
 */
class Validator
{
    private array $data;
    private array $rules;
    private array $errors = [];
    private array $customValidators = [];

    /**
     * Create a new Validator instance
     *
     * @param array $data The data to validate
     * @param array $rules The validation rules
     */
    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    /**
     * Register a custom validator
     *
     * @param string $name The validator name
     * @param callable $callback The validation callback (receives value, parameters)
     * @return void
     */
    public function addCustomValidator(string $name, callable $callback): void
    {
        $this->customValidators[$name] = $callback;
    }

    /**
     * Validate the data against the rules
     *
     * @return bool True if validation passes, false otherwise
     */
    public function validate(): bool
    {
        $this->errors = [];

        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_string($ruleSet) ? explode('|', $ruleSet) : $ruleSet;

            // Dotted array-wildcard paths (e.g. "items.*.quantity") are resolved
            // against the nested $data structure and expanded into one concrete
            // entry per matching array element (e.g. "items.0.quantity",
            // "items.1.quantity", ...). A PLAIN dotted path with no wildcard
            // segment (e.g. "address.city") is resolved the same way, walking
            // straight down without expansion.
            //
            // Before this, `$field` was used as a literal top-level array key —
            // "items.*.quantity" never matches any key in $data (nested arrays
            // don't have literal dotted keys), so `$value` was always null and
            // every rule silently evaluated against that null. A `required`
            // rule then failed for every request no matter the payload (see
            // PostInvoicesRoute regression), while non-required rules (numeric,
            // min, etc.) silently no-op'd on null and never actually checked
            // the real per-item data.
            if (str_contains($field, '.')) {
                $resolved = $this->resolvePaths($field);

                foreach ($resolved as $resolvedField => $value) {
                    foreach ($rules as $rule) {
                        $this->applyRule($resolvedField, $value, $rule);
                    }
                }

                // No matches at all (e.g. the parent array itself is missing) —
                // leave it to the parent field's own rule (e.g. "items" =>
                // "required|array") to report that; emitting a synthetic error
                // here under a fabricated field name would just duplicate it.
                continue;
            }

            $value = $this->data[$field] ?? null;

            foreach ($rules as $rule) {
                $this->applyRule($field, $value, $rule);
            }
        }

        return empty($this->errors);
    }

    /**
     * Resolve a dotted field path (optionally containing "*" wildcard
     * segments meaning "every element of the array at this position")
     * against $this->data, returning a flat map of concrete resolved field
     * label => value for every match found.
     *
     * Example: "items.*.item_id" against
     *   ['items' => [['item_id' => 1], ['item_id' => 2]]]
     * resolves to ['items.0.item_id' => 1, 'items.1.item_id' => 2].
     *
     * @return array<string, mixed>
     */
    private function resolvePaths(string $field): array
    {
        $results = [];
        $this->resolveSegments($this->data, explode('.', $field), '', $results);
        return $results;
    }

    /**
     * @param mixed $current
     * @param array<int, string> $segments
     * @param array<string, mixed> $results
     */
    private function resolveSegments($current, array $segments, string $pathSoFar, array &$results): void
    {
        if (empty($segments)) {
            $results[$pathSoFar] = $current;
            return;
        }

        $segment = array_shift($segments);

        if ($segment === '*') {
            if (!is_array($current)) {
                return;
            }

            foreach ($current as $key => $item) {
                $newPath = $pathSoFar === '' ? (string)$key : "$pathSoFar.$key";
                $this->resolveSegments($item, $segments, $newPath, $results);
            }

            return;
        }

        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return;
        }

        $newPath = $pathSoFar === '' ? $segment : "$pathSoFar.$segment";
        $this->resolveSegments($current[$segment], $segments, $newPath, $results);
    }

    /**
     * Apply a single validation rule
     *
     * @param string $field The field name
     * @param mixed $value The field value
     * @param string $rule The rule to apply
     * @return void
     */
    private function applyRule(string $field, $value, string $rule): void
    {
        // Parse rule and parameters (e.g., "min:3" -> ["min", "3"])
        $parts = explode(':', $rule, 2);
        $ruleName = $parts[0];
        $parameter = $parts[1] ?? null;

        // Check custom validators first
        if (isset($this->customValidators[$ruleName])) {
            $callback = $this->customValidators[$ruleName];
            if (!$callback($value, $parameter)) {
                $this->addError($field, $ruleName, $parameter);
            }
            return;
        }

        // Apply built-in validators
        $methodName = 'validate' . ucfirst($ruleName);
        if (method_exists($this, $methodName)) {
            if (!$this->$methodName($value, $parameter)) {
                $this->addError($field, $ruleName, $parameter);
            }
        }
    }

    /**
     * Validate that a field is required
     */
    private function validateRequired($value, $parameter): bool
    {
        if (is_null($value)) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        if (is_array($value) && empty($value)) {
            return false;
        }

        return true;
    }

    /**
     * Validate that a field is a valid email
     */
    private function validateEmail($value, $parameter): bool
    {
        if (is_null($value)) {
            return true; // Use 'required' to check for presence
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate minimum length/value
     */
    private function validateMin($value, $parameter): bool
    {
        if (is_null($value)) {
            return true; // Use 'required' to check for presence
        }

        $parameter = (int)$parameter;

        if (is_string($value)) {
            return mb_strlen($value) >= $parameter;
        }

        if (is_numeric($value)) {
            return $value >= $parameter;
        }

        if (is_array($value)) {
            return count($value) >= $parameter;
        }

        return false;
    }

    /**
     * Validate maximum length/value
     */
    private function validateMax($value, $parameter): bool
    {
        if (is_null($value)) {
            return true; // Use 'required' to check for presence
        }

        $parameter = (int)$parameter;

        if (is_string($value)) {
            return mb_strlen($value) <= $parameter;
        }

        if (is_numeric($value)) {
            return $value <= $parameter;
        }

        if (is_array($value)) {
            return count($value) <= $parameter;
        }

        return false;
    }

    /**
     * Validate that a field is numeric
     */
    private function validateNumeric($value, $parameter): bool
    {
        if (is_null($value)) {
            return true; // Use 'required' to check for presence
        }

        return is_numeric($value);
    }

    /**
     * Validate that a field is an integer
     */
    private function validateInteger($value, $parameter): bool
    {
        if (is_null($value)) {
            return true; // Use 'required' to check for presence
        }

        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Validate that a field is a string
     */
    private function validateString($value, $parameter): bool
    {
        if (is_null($value)) {
            return true; // Use 'required' to check for presence
        }

        return is_string($value);
    }

    /**
     * Validate that a field is an array
     */
    private function validateArray($value, $parameter): bool
    {
        if (is_null($value)) {
            return true; // Use 'required' to check for presence
        }

        return is_array($value);
    }

    /**
     * Validate that a field is a boolean
     */
    private function validateBoolean($value, $parameter): bool
    {
        if (is_null($value)) {
            return true; // Use 'required' to check for presence
        }

        return is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true);
    }

    /**
     * Validate that a field matches a regex pattern
     */
    private function validateRegex($value, $parameter): bool
    {
        if (is_null($value)) {
            return true; // Use 'required' to check for presence
        }

        return preg_match($parameter, $value) === 1;
    }

    /**
     * Validate that a field is in a list of values
     */
    private function validateIn($value, $parameter): bool
    {
        if (is_null($value)) {
            return true; // Use 'required' to check for presence
        }

        $validValues = explode(',', $parameter);
        return in_array($value, $validValues, true);
    }

    /**
     * Validate that a field is a valid URL
     */
    private function validateUrl($value, $parameter): bool
    {
        if (is_null($value)) {
            return true; // Use 'required' to check for presence
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Add an error for a field
     */
    private function addError(string $field, string $rule, $parameter = null): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }

        $this->errors[$field][] = $this->getErrorMessage($field, $rule, $parameter);
    }

    /**
     * Get error message for a rule
     */
    private function getErrorMessage(string $field, string $rule, $parameter = null): string
    {
        $messages = [
            'required' => "The $field field is required.",
            'email' => "The $field must be a valid email address.",
            'min' => "The $field must be at least $parameter.",
            'max' => "The $field must not exceed $parameter.",
            'numeric' => "The $field must be a number.",
            'integer' => "The $field must be an integer.",
            'string' => "The $field must be a string.",
            'array' => "The $field must be an array.",
            'boolean' => "The $field must be a boolean.",
            'regex' => "The $field format is invalid.",
            'in' => "The $field must be one of: $parameter.",
            'url' => "The $field must be a valid URL.",
        ];

        return $messages[$rule] ?? "The $field is invalid.";
    }

    /**
     * Get all validation errors
     *
     * @return array
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get the first error message
     *
     * @return string|null
     */
    public function firstError(): ?string
    {
        if (empty($this->errors)) {
            return null;
        }

        $firstField = array_key_first($this->errors);
        return $this->errors[$firstField][0] ?? null;
    }

    /**
     * Get all error messages as a flat array
     *
     * @return array
     */
    public function errorMessages(): array
    {
        $messages = [];
        foreach ($this->errors as $field => $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $messages[] = $error;
            }
        }
        return $messages;
    }

    /**
     * Static factory method for quick validation
     *
     * @param array $data The data to validate
     * @param array $rules The validation rules
     * @return Validator
     */
    public static function make(array $data, array $rules): Validator
    {
        return new self($data, $rules);
    }
}