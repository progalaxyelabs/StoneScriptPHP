<?php

namespace App\Routes;

use Framework\Routing\BaseRoute;
use Framework\Http\ApiResponse;
use Framework\Validation\Validator;

/**
 * Sample POST route with validation - Create User
 *
 * Usage:
 *   POST http://localhost:9100/users
 *   Body: {
 *     "email": "user@example.com",
 *     "name": "John Doe",
 *     "age": 25
 *   }
 */
class UsersRoute extends BaseRoute
{
    /**
     * Validation rules for creating a user
     */
    protected function rules(): array
    {
        return [
            'email' => 'required|email',
            'name' => 'required|string|min:2|max:100',
            'age' => 'required|integer|min:18|max:120'
        ];
    }

    /**
     * Process the request
     */
    public function process(): ApiResponse
    {
        // Get validated input
        $email = $this->input('email');
        $name = $this->input('name');
        $age = $this->input('age');

        // In a real application, you would:
        // 1. Call a database function to insert the user
        // 2. Return the created user data
        //
        // Example:
        // $userId = FnCreateUser::run($email, $name, $age);
        // $user = FnGetUser::run($userId);

        // For this example, we'll return mock data
        return new ApiResponse('ok', 'User created successfully', [
            'user' => [
                'id' => 1,
                'email' => $email,
                'name' => $name,
                'age' => $age,
                'created_at' => date('c')
            ]
        ]);
    }
}
