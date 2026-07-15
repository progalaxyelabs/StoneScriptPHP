<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Middleware;

/**
 * AuthWiringException — a boot-time typed-auth wiring/migration failure.
 *
 * Thrown by {@see AuthMiddlewareRegistrar::assertFullyWired()} when a platform boots
 * with protected routes but has NOT wired the typed-auth middleware this release
 * requires. `Application::run()` catches this SPECIFIC type and fails fast with the
 * exception's actionable message, rather than letting a bare stack trace / opaque 500
 * escape. The message IS the migration aid.
 *
 * @package StoneScriptPHP\Auth\Middleware
 * @since   7.0.0
 */
class AuthWiringException extends \RuntimeException
{
}
