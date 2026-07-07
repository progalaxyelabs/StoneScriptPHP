<?php

namespace StoneScriptPHP;

/**
 * A route handler response that issues an HTTP redirect instead of a JSON body.
 *
 * Subclasses ApiResponse (not a new interface) so it passes the `instanceof
 * ApiResponse` check in Router::executeHandler() unchanged — IRouteHandler's
 * `process(): ApiResponse` signature and every existing handler are
 * unaffected. Application::run() special-cases this type at the very end of
 * its output step, before the default JSON-encoding path.
 */
class RedirectResponse extends ApiResponse
{
    public string $location;

    public function __construct(string $location, int $httpStatusCode = 302)
    {
        parent::__construct('ok', '', null, $httpStatusCode);
        $this->location = $location;
    }
}
