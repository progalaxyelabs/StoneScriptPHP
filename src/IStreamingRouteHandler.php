<?php

declare(strict_types=1);

namespace StoneScriptPHP;

/**
 * Marker for a route handler that streams (SSE, chunked, long-poll). HEAD on such a route is always
 * answered as a probe (headers only, handler NOT run) so a monitor cannot pin a PHP worker on a stream
 * that never ends. Declaring `streaming: true` on the route has the same effect; implement this
 * interface when you cannot rely on every route entry remembering the flag.
 */
interface IStreamingRouteHandler
{
}
