<?php

namespace StoneScriptPHP;

/**
 * A route handler response that emits raw HTML instead of a JSON body.
 *
 * Subclasses ApiResponse for the same reason as RedirectResponse (see that
 * class's docblock) — no framework-wide type changes needed. Primary use
 * case: the postMessage-bridge page an OAuth callback route renders to hand
 * a result back to a popup's opener window (see Auth\BuiltinOAuth\GoogleOAuthRoutes).
 */
class HtmlResponse extends ApiResponse
{
    public string $html;

    public function __construct(string $html, ?int $httpStatusCode = 200)
    {
        parent::__construct('ok', '', null, $httpStatusCode);
        $this->html = $html;
    }
}
