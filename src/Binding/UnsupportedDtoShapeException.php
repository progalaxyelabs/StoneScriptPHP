<?php

declare(strict_types=1);

namespace StoneScriptPHP\Binding;

/**
 * Thrown when a DTO itself is malformed for {@see DtoHydrator} — e.g. a
 * constructor parameter typed as a union beyond simple `?T` nullability, or
 * `iterable`/`callable`/`self`/`static`. This is a DEVELOPER-time error (the
 * DTO's author must fix the class), never a request-shape problem — it is
 * therefore never turned into a 400. It is meant to fail loud in dev/CI, not
 * be silently swallowed into a generic 500 in production.
 */
class UnsupportedDtoShapeException extends \LogicException
{
}
