<?php

declare(strict_types=1);

namespace StoneScriptPHP\Persistence;

use StoneScriptPHP\ApiResponse;

/**
 * Last line of defence, applied by the router to the FINAL response of every request, after all
 * middleware and the handler have run: a failure must never ship as HTTP 2xx.
 *
 *  1. An error-shaped body (`status` = `error` | `not ok`) that no code ever gave an HTTP status
 *     would be emitted as 200. It gets 500 (`error`) / 400 (`not ok`). A status that any middleware
 *     or handler already set (via the response or `http_response_code()`) is never touched.
 *  2. A 2xx that follows a {@see PersistenceException} nobody acknowledged
 *     ({@see PersistenceLedger::handled()}) is a handler that swallowed a failed write and answered
 *     success. It becomes a 500 error response.
 *
 * Only active under {@see PersistenceContract::ENFORCED}; under `lenient` the response is returned
 * unchanged and a deprecation notice says what would have happened.
 */
final class ResponseGuard
{
    public static function apply(ApiResponse $response): ApiResponse
    {
        // Redirects/HTML are not JSON envelopes; their status is theirs.
        if ($response instanceof \StoneScriptPHP\RedirectResponse || $response instanceof \StoneScriptPHP\HtmlResponse) {
            return $response;
        }

        $current = self::currentStatus($response);

        // (1) error body, no real status
        if ($response->httpStatusCode === null && $current < 400
            && ($response->status === 'error' || $response->status === 'not ok')) {
            $to = $response->status === 'not ok' ? 400 : 500;
            if (!PersistenceContract::enforced()) {
                PersistenceContract::wouldEnforce(
                    'error-body-2xx',
                    "a response with status '{$response->status}' and no HTTP status would ship as HTTP $current; enforced mode sends $to."
                );
                return $response;
            }
            $response->httpStatusCode = $to;
            if (!headers_sent()) {
                http_response_code($to);
            }
            return $response;
        }

        // (2) success after a swallowed write failure
        if ($current < 400 && ($response->httpStatusCode === null || $response->httpStatusCode < 400)) {
            $unhandled = PersistenceLedger::unhandled();
            if ($unhandled !== []) {
                $first = $unhandled[0];
                $detail = sprintf(
                    "a write failed (%s: %s) but the request is answering HTTP %d. Handlers that deliberately recover must call PersistenceLedger::handled(\$e).",
                    $first->function() ?? 'write',
                    $first->reason(),
                    $current
                );
                if (!PersistenceContract::enforced()) {
                    PersistenceContract::wouldEnforce('success-after-failed-write', ucfirst($detail));
                    return $response;
                }
                log_error('Persistence contract: ' . $detail);
                if (!headers_sent()) {
                    http_response_code(500);
                }
                return new ApiResponse('error', PersistenceException::DEFAULT_MESSAGE, null, 500);
            }
        }

        return $response;
    }

    private static function currentStatus(ApiResponse $response): int
    {
        if ($response->httpStatusCode !== null) {
            return $response->httpStatusCode;
        }
        $global = http_response_code();
        return is_int($global) ? $global : 200;
    }
}
