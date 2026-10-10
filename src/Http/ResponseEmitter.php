<?php

declare(strict_types=1);

namespace StoneScriptPHP\Http;

use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\HtmlResponse;
use StoneScriptPHP\RedirectResponse;

/**
 * Turns an {@see ApiResponse} into status + headers + body and writes it out.
 *
 * Split in two on purpose: {@see self::render()} is pure (testable without
 * touching PHP's output layer); {@see self::emit()} performs the I/O.
 *
 * HEAD (RFC 9110 section 9.3.2): the response carries the same status and
 * header fields a GET would, and NO body. `Content-Length` is advertised when
 * the body length is known (it always is here), so a HEAD probe sees exactly
 * what GET would have returned. Responses whose status forbids a body
 * (1xx, 204, 304) never carry one regardless of method.
 */
final class ResponseEmitter
{
    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function render(ApiResponse $response, string $method, int $currentStatus = 200): array
    {
        $status = $response->httpStatusCode ?? $currentStatus;
        $headers = [];

        if ($response instanceof RedirectResponse) {
            $headers['Location'] = $response->location;
            // A redirect has never carried a body or Content-Type here; keep that.
            $body = '';
            $contentType = null;
        } elseif ($response instanceof HtmlResponse) {
            $contentType = 'text/html; charset=utf-8';
            $body = $response->html;
        } else {
            $contentType = 'application/json';
            $body = $response->toJson();
        }

        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }
        foreach ($response->headers as $name => $value) {
            // Response-supplied headers win (e.g. a streaming Content-Type on HEAD).
            foreach (array_keys($headers) as $existing) {
                if (strcasecmp($existing, (string) $name) === 0) {
                    unset($headers[$existing]);
                }
            }
            $headers[(string) $name] = (string) $value;
        }

        $bodyless = $status < 200 || $status === 204 || $status === 304;
        if ($bodyless) {
            $body = '';
        } elseif (strtoupper($method) === 'HEAD') {
            // Same Content-Length a GET would carry; body itself suppressed. A probe (handler not run)
            // and any event stream have no known length: never advertise one.
            if (!$response->headProbe) {
                $headers['Content-Length'] = (string) strlen($body);
            }
            $body = '';
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /** Write the rendered response using header()/echo. */
    public static function emit(ApiResponse $response, string $method): void
    {
        $out = self::render($response, $method, http_response_code() ?: 200);

        if ($response->httpStatusCode !== null) {
            http_response_code($out['status']);
        }
        if (!headers_sent()) {
            foreach ($out['headers'] as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $out['body'];
    }
}
