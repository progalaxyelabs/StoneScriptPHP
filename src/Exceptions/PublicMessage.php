<?php

declare(strict_types=1);

namespace StoneScriptPHP\Exceptions;

/**
 * Marker: this exception's message was written for the client and may be shown as-is.
 *
 * Every other exception message is treated as internal (it can embed database values, paths, identifiers) and never
 * reaches a response, in any mode: the client gets a generic sentence for the status plus a correlation id.
 * {@see FrameworkException} implements it. A platform's own business exception opts in by implementing it, e.g.
 * `final class OutOfStock extends \RuntimeException implements PublicMessage {}` (set the 4xx code on it).
 */
interface PublicMessage
{
}
