<?php

declare(strict_types=1);

namespace StoneScriptPHP;

/**
 * Marker interface for the typed-request-binder route pattern
 * (SPEC-typed-request-binder.md). Implementing classes must additionally
 * declare exactly one public method:
 *
 *   public function execute(FooRequest $request): FooResponse
 *
 * with concrete DTO class types (not `object`, not a union) for both the
 * parameter and the return type. This CANNOT be expressed as a real
 * interface method — PHP enforces parameter-type contravariance on
 * interface implementations, so an interface method typed
 * `execute(object $request): object` cannot legally be narrowed by a
 * concrete class to `execute(FooRequest $request): FooResponse` (confirmed
 * fatal: "Declaration ... must be compatible with ..."). This interface is
 * therefore a pure `instanceof` routing switch — `Router::executeHandler()`
 * locates and reflects the handler's own `execute()` method at dispatch
 * time rather than relying on the interface to type it.
 *
 * A route implementing this interface needs NO `process()`, NO
 * `validation_rules()`, and NO `public ?array` properties — the framework
 * hydrates the request DTO (via `StoneScriptPHP\Binding\DtoHydrator`) from
 * the route's declared `request:` DTO class (see `routes.php`), calls
 * `execute()`, and wraps the returned response DTO into the standard
 * `ApiResponse` envelope. Business-rule validation (uniqueness, numeric
 * ranges, etc. — as opposed to shape/type/presence, which the hydrator
 * already rejects with a 400) stays inside `execute()`, expressed as a
 * thrown `\RuntimeException($message, $httpCode)`.
 *
 * This interface is entirely additive: existing `IRouteHandler` routes are
 * completely unaffected, and a handler may implement BOTH interfaces during
 * a migration if useful, though `Router` only inspects `execute()` when
 * `ITypedRouteHandler` is present — `process()`/`validation_rules()` are
 * simply not invoked in that case.
 */
interface ITypedRouteHandler
{
}
