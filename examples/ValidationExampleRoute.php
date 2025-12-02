<?php

namespace Examples;

use Framework\ApiResponse;
use Framework\IRouteHandler;

/**
 * Example route demonstrating the validation layer
 *
 * This example shows how to use various validation rules
 * to validate incoming request data.
 */
class ValidationExampleRoute implements IRouteHandler
{
    // Required fields
    public string $name;
    public string $email;
    public int $age;

    // Optional fields
    public ?string $website;
    public ?string $bio;
    public ?array $interests;

    /**
     * Define validation rules for the request
     *
     * @return array Validation rules for each field
     */
    function validation_rules(): array
    {
        return [
            // Required string with length constraints
            'name' => 'required|string|min:2|max:50',

            // Required valid email
            'email' => 'required|email',

            // Required integer with range constraints
            'age' => 'required|integer|min:18|max:120',

            // Optional URL (only validated if provided)
            'website' => 'url',

            // Optional bio with max length
            'bio' => 'string|max:500',

            // Optional array with item count constraints
            'interests' => 'array|min:1|max:10'
        ];
    }

    /**
     * Process the validated request
     *
     * This method is only called if validation passes.
     * All validated data is available as class properties.
     *
     * @return ApiResponse
     */
    function process(): ApiResponse
    {
        // At this point, all validation has passed
        // You can safely use the validated data

        $userData = [
            'name' => $this->name,
            'email' => $this->email,
            'age' => $this->age,
            'website' => $this->website ?? null,
            'bio' => $this->bio ?? null,
            'interests' => $this->interests ?? []
        ];

        // Additional business logic can go here
        // For example, checking if email already exists in database

        return res_ok($userData, 'Validation successful');
    }
}

/**
 * Example: Request that will PASS validation
 *
 * POST /api/validation-example
 * Content-Type: application/json
 *
 * {
 *   "name": "John Doe",
 *   "email": "john@example.com",
 *   "age": 25,
 *   "website": "https://johndoe.com",
 *   "bio": "Software developer",
 *   "interests": ["coding", "music", "sports"]
 * }
 *
 * Response: 200 OK
 * {
 *   "status": "ok",
 *   "message": "Validation successful",
 *   "data": {
 *     "name": "John Doe",
 *     "email": "john@example.com",
 *     "age": 25,
 *     "website": "https://johndoe.com",
 *     "bio": "Software developer",
 *     "interests": ["coding", "music", "sports"]
 *   }
 * }
 */

/**
 * Example: Request that will FAIL validation
 *
 * POST /api/validation-example
 * Content-Type: application/json
 *
 * {
 *   "name": "J",
 *   "email": "not-an-email",
 *   "age": 15,
 *   "website": "invalid-url"
 * }
 *
 * Response: 400 Bad Request (in DEBUG_MODE)
 * {
 *   "status": "error",
 *   "message": "Validation failed",
 *   "data": {
 *     "name": ["The name must be at least 2."],
 *     "email": ["The email must be a valid email address."],
 *     "age": ["The age must be at least 18."],
 *     "website": ["The website must be a valid URL."]
 *   }
 * }
 *
 * Response: 400 Bad Request (production mode, DEBUG_MODE = false)
 * {
 *   "status": "error",
 *   "message": "Validation failed"
 * }
 */

/**
 * Example: Minimal valid request (only required fields)
 *
 * POST /api/validation-example
 * Content-Type: application/json
 *
 * {
 *   "name": "Jane Smith",
 *   "email": "jane@example.com",
 *   "age": 30
 * }
 *
 * Response: 200 OK
 * {
 *   "status": "ok",
 *   "message": "Validation successful",
 *   "data": {
 *     "name": "Jane Smith",
 *     "email": "jane@example.com",
 *     "age": 30,
 *     "website": null,
 *     "bio": null,
 *     "interests": []
 *   }
 * }
 */
