<?php

namespace StoneScriptPHP\Auth\BuiltinOAuth;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\RedirectResponse;
use StoneScriptPHP\HtmlResponse;
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
        /** @var array<int, string>|null allowed opener origins; null = Env ALLOWED_ORIGINS */
        private readonly ?array $allowedOrigins = null,
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
        // Bind the flow to the window that opened the popup. The browser sends the opener's origin as the
        // Referer of the popup navigation. A present-but-unlisted origin never gets as far as Google. An absent
        // Referer (Referrer-Policy: no-referrer) is tolerated: the callback then posts only to the allowed origins.
        $allowed = OpenerOrigins::allowed($this->allowedOrigins);
        $opener = OpenerOrigins::fromReferer($_SERVER['HTTP_REFERER'] ?? null);
        if ($opener !== null && !in_array($opener, $allowed, true)) {
            log_error('GoogleOAuthInitiateRoute: sign-in popup refused - opener origin ' . $opener . ' is not in ALLOWED_ORIGINS (add it, run `php stone auth:check-origins`)');
            return OAuthConfigErrorPage::response(
                'This site is not authorised to start sign-in.',
                'The site administrator must add ' . $opener . ' to the server setting ALLOWED_ORIGINS.',
                403
            );
        }
        $claims = ['purpose' => 'oauth_state', 'provider' => 'google'];
        if ($opener !== null) {
            $claims['opener_origin'] = $opener;
        }
        $state = $this->jwtHandler->generateToken($claims, 600, 'oauth_state');

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
