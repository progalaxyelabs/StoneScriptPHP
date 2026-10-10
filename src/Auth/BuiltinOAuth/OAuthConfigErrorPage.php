<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\BuiltinOAuth;

use StoneScriptPHP\HtmlResponse;

/**
 * A human-readable page for the sign-in popup when sign-in cannot complete because of a SERVER CONFIGURATION
 * problem (no/unsuitable ALLOWED_ORIGINS). It never contains tokens or any user data. All dynamic text is escaped.
 */
final class OAuthConfigErrorPage
{
    public static function response(string $headline, string $detail, int $status = 403): HtmlResponse
    {
        $h = htmlspecialchars($headline, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $d = htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Sign-in unavailable</title></head>'
            . '<body style="font-family:sans-serif;max-width:32em;margin:3em auto;padding:0 1em">'
            . '<h1 style="font-size:1.2em">' . $h . '</h1><p>' . $d . '</p>'
            . '<p>You can close this window. Nothing was signed in.</p></body></html>';
        return new HtmlResponse($html, $status);
    }
}
