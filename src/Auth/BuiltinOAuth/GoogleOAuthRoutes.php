<?php

namespace StoneScriptPHP\Auth\BuiltinOAuth;

use StoneScriptPHP\Routing\Router;
use StoneScriptPHP\Auth\JwtHandlerInterface;
use StoneScriptPHP\Auth\RsaJwtHandler;

/**
 * Builtin (standalone, no central auth service) Google OAuth popup flow.
 *
 * Registers:
 *   GET {prefix}/oauth/google           - redirects to Google's consent screen
 *   GET {prefix}/oauth/google/callback  - completes the exchange, renders the
 *                                         postMessage-bridge HTML page
 *
 * This is the exact contract ngx-stonescriptphp-client's
 * StoneScriptPHPAuth.loginWithProvider('google') already calls out of the
 * box (`GET {accountsUrl}/oauth/google?...` opened as a popup) — once this
 * is registered, a consuming app needs NO custom AuthPlugin for Google
 * sign-in; the library's stock plugin works as-is.
 *
 * Usage (in your Application::run() config, AUTH_MODE=builtin):
 *   GoogleOAuthRoutes::register($router, [
 *       'client_id'     => $env->GOOGLE_CLIENT_ID,
 *       'client_secret' => $env->GOOGLE_CLIENT_SECRET,
 *       'redirect_uri'  => $env->GOOGLE_REDIRECT_URI,
 *       'user_resolver' => new MyGoogleOAuthUserResolver(), // app-specific upsert
 *   ]);
 *
 * Note: `prefix` intentionally defaults to '' (routes at the domain root,
 * NOT under /auth) — the frontend library calls `{apiHost}/oauth/google`
 * directly, not `{apiHost}/auth/oauth/google`. Override only if you also
 * change the frontend's configured accountsUrl accordingly.
 */
class GoogleOAuthRoutes
{
    public static function register(Router $router, array $options): void
    {
        $clientId = $options['client_id'] ?? null;
        $clientSecret = $options['client_secret'] ?? null;
        $redirectUri = $options['redirect_uri'] ?? null;
        $userResolver = $options['user_resolver'] ?? null;
        $jwtHandler = $options['jwt_handler'] ?? new RsaJwtHandler();
        $prefix = rtrim($options['prefix'] ?? '', '/');

        if (empty($clientId) || empty($clientSecret) || empty($redirectUri)) {
            throw new \InvalidArgumentException(
                'GoogleOAuthRoutes::register requires client_id, client_secret, and redirect_uri'
            );
        }

        if (!($userResolver instanceof GoogleOAuthUserResolver)) {
            throw new \InvalidArgumentException(
                'GoogleOAuthRoutes::register requires a user_resolver implementing GoogleOAuthUserResolver'
            );
        }

        if (!($jwtHandler instanceof JwtHandlerInterface)) {
            throw new \InvalidArgumentException('jwt_handler must implement JwtHandlerInterface');
        }

        $initiatePath = "$prefix/oauth/google";
        $callbackPath = "$prefix/oauth/google/callback";

        // Both public — no JWT exists yet when a user is trying to sign in.
        $router->get(
            $initiatePath,
            new GoogleOAuthInitiateRoute($clientId, $clientSecret, $redirectUri, $jwtHandler),
            [],
            true
        );
        $router->get(
            $callbackPath,
            new GoogleOAuthCallbackRoute($clientId, $clientSecret, $redirectUri, $jwtHandler, $userResolver),
            [],
            true
        );

        log_info("GoogleOAuthRoutes: Registered GET $initiatePath and GET $callbackPath");
    }
}
