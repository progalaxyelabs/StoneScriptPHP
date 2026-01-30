<?php

namespace StoneScriptPHP\Auth\Middleware;

use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\ApiResponse;

/**
 * RequireIssuerMiddleware
 *
 * Validates that the authenticated user's token was issued by a specific issuer.
 * Used with multi-auth setups to apply different authorization rules based on issuer.
 *
 * Example use cases:
 * - Employee-only endpoints: require 'employee' issuer type
 * - Customer-only endpoints: require 'customer' issuer type
 * - Mixed endpoints: allow either issuer but apply different permissions
 *
 * Usage:
 * ```php
 * // Employee-only endpoint
 * $router->get('/admin/users', AdminController::class, 'listUsers')
 *     ->middleware(new ValidateJwtMiddleware($jwtHandler))
 *     ->middleware(new RequireIssuerMiddleware(['employee']));
 *
 * // Allow both customer and employee, but with different permissions
 * $router->get('/api/orders', OrderController::class, 'getOrders')
 *     ->middleware(new ValidateJwtMiddleware($jwtHandler))
 *     ->middleware(new RequireIssuerMiddleware(['customer', 'employee']));
 * ```
 */
class RequireIssuerMiddleware implements MiddlewareInterface
{
    private array $allowedIssuers;

    /**
     * @param array $allowedIssuers List of allowed issuer types (e.g., ['customer', 'employee'])
     */
    public function __construct(array $allowedIssuers)
    {
        $this->allowedIssuers = $allowedIssuers;
    }

    public function handle(array $request, callable $next): ?ApiResponse
    {
        // Check if JWT claims are present (set by ValidateJwtMiddleware)
        if (!isset($request['jwt_claims'])) {
            http_response_code(401);
            return new ApiResponse('error', 'Unauthorized: No JWT claims found');
        }

        $claims = $request['jwt_claims'];

        // Check if issuer_type is present (set by MultiAuthJwtValidator)
        if (!isset($claims['issuer_type'])) {
            // If no issuer_type, this is a single-issuer setup - allow through
            return $next($request);
        }

        $issuerType = $claims['issuer_type'];

        // Validate issuer type
        if (!in_array($issuerType, $this->allowedIssuers, true)) {
            http_response_code(403);
            return new ApiResponse(
                'error',
                "Forbidden: This endpoint requires one of [" . implode(', ', $this->allowedIssuers) . "] issuer type. You have '$issuerType'"
            );
        }

        // Continue to next middleware
        return $next($request);
    }
}
