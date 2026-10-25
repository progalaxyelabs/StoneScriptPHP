<?php

declare(strict_types=1);

namespace StoneScriptPHP\Http;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\HtmlResponse;
use StoneScriptPHP\RedirectResponse;
use StoneScriptPHP\RequestLogging\RequestContext;

/**
 * The single place where the FINAL response of a request is made truthful and recorded. Applied by
 * {@see \StoneScriptPHP\Routing\Router::dispatch()} to every response, always (no opt-in):
 *
 *  1. The response OBJECT and the process status agree. Code that only called `http_response_code(404)` left
 *     `ApiResponse::$httpStatusCode` null; any layer that reads the object (platform middleware, the emitter)
 *     then saw "no status". The process status (>= 400) is copied onto the object.
 *  2. An error-shaped body (`status` = `error` / `not ok`) that nothing ever gave a failing HTTP status is never
 *     shipped as 2xx: `error` -> 500, `not ok` -> 400. (A status that was set deliberately is never changed.)
 *  3. Every 4xx/5xx is recorded in {@see RequestContext} with a safe message, unless a real failure (exception)
 *     was already recorded for the request.
 */
final class ResponseFinalizer
{
    public static function apply(ApiResponse $response): ApiResponse
    {
        if ($response instanceof RedirectResponse || $response instanceof HtmlResponse) {
            return $response;
        }

        $process = http_response_code();
        $process = is_int($process) ? $process : 200;

        if ($response->httpStatusCode === null && $process >= 400) {
            $response->httpStatusCode = $process;
        }

        $effective = $response->httpStatusCode ?? $process;
        if ($response->httpStatusCode === null && $effective < 400
            && ($response->status === 'error' || $response->status === 'not ok')) {
            $effective = $response->status === 'not ok' ? 400 : 500;
            $response->httpStatusCode = $effective;
            if (!headers_sent()) {
                http_response_code($effective);
            }
        }

        if ($effective >= 400) {
            RequestContext::captureResponse($effective, (string) $response->message);
        }

        return $response;
    }
}
