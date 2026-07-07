<?php

namespace StoneScriptPHP\Auth\BuiltinOAuth;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\RedirectResponse;
use StoneScriptPHP\Auth\JwtHandlerInterface;
use Google\Client as GoogleClient;

/**
 * GET {prefix}/oauth/google
 *
 * Redirects the browser (opened as a popup by ngx-stonescriptphp-client's
 * StoneScriptPHPAuth.loginWithProvider('google')) to Google's own consent
 * screen. The `state` param is a short-lived signed JWT (via the same
 * JwtHandlerInterface this platform already uses for sessions) — stateless
 * CSRF protection, no Redis/session store needed. See GoogleOAuthCallbackRoute
 * for the other half of the flow.
 */
class GoogleOAuthInitiateRoute implements IRouteHandler
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly JwtHandlerInterface $jwtHandler,
    ) {
    }

    public function validation_rules(): array
    {
        // Client sends platform_code/mode/intent query params as hints for its
        // own bookkeeping — nothing here needs to read or validate them; a
        // single-platform builtin app has exactly one Google client anyway.
        return [];
    }

    public function process(): ApiResponse
    {
        // 10-minute state token: long enough for a user to sit on Google's
        // consent screen, short enough that a leaked/replayed value is
        // low-value. See GoogleOAuthCallbackRoute::process() for verification.
        $state = $this->jwtHandler->generateToken(
            ['purpose' => 'oauth_state', 'provider' => 'google'],
            600,
            'oauth_state'
        );

        $client = new GoogleClient();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        $client->setScopes(['openid', 'email', 'profile']);
        $client->setState($state);
        $client->setAccessType('online');

        return new RedirectResponse($client->createAuthUrl());
    }
}
