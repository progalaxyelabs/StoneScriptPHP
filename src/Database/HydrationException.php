<?php

declare(strict_types=1);

namespace StoneScriptPHP\Database;

/**
 * A database result row could not be mapped onto a model class without
 * inventing or losing information (NULL into a non-nullable property, a value
 * that does not convert to the declared type, an unsupported property type).
 *
 * Extends \RuntimeException WITHOUT an HTTP-range code on purpose: this is a
 * server-side contract mismatch between the SQL function and the model class,
 * i.e. a 500, never a client error.
 */
final class HydrationException extends \RuntimeException
{
}
