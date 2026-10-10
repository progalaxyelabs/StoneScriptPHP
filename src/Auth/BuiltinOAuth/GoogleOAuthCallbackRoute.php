<?php

namespace StoneScriptPHP\Auth\BuiltinOAuth;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\HtmlResponse;
use StoneScriptPHP\Env;
use StoneScriptPHP\Auth\JwtHandlerInterface;
use StoneScriptPHP\Auth\RefreshTokens\RefreshTokenIssuer;
use StoneScriptPHP\Auth\TokenClaims;
use Google\Client as GoogleClient;

/**
 * GET {prefix}/oauth/google/callback
 *
 * Google redirects here after the user grants/denies consent. Exchanges the
 * auth code for tokens, verifies the ID token (same JWKS/aud/iss/exp checks
 * as the framework's ID-token-verify template — see
 * src/Templates/Auth/google/GoogleOauthRoute.php.template), resolves the
 * local user via the app-supplied GoogleOAuthUserResolver, mints this
 * platform's own JWT, and renders an HTML page whose entire body is a
 * `window.opener.postMessage(...)` — the exact contract
 * ngx-stonescriptphp-client's StoneScriptPHPAuth.loginWithProvider() already
 * listens for (oauth_success / oauth_error message types).
 *
 * Always resolves via postMessage, never a bare JSON error — a JSON body
 * here would leave the popup's opener waiting forever with no signal at all.
 */
class GoogleOAuthCallbackRoute implements IRouteHandler
{
    public string $code = '';
    public string $state = '';
    public string $error = '';

    /** Origin the OAuth state was bound to at initiate time (from the verified state token). */
    private ?string $openerOrigin = null;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly JwtHandlerInterface $jwtHandler,
        private readonly GoogleOAuthUserResolver $userResolver,
        private readonly ?RefreshTokenIssuer $issuer = null,
        /** @var array<int, string>|null allowed opener origins; null = Env ALLOWED_ORIGINS */
        private readonly ?array $allowedOrigins = null,
    ) {
    }

    public function validation_rules(): array
    {
        return [
            'code' => 'optional|string',
            'state' => 'optional|string',
            'error' => 'optional|string',
        ];
    }

    public function process(): ApiResponse
    {
        if (!empty($this->error)) {
            log_info('GoogleOAuthCallbackRoute: user denied or cancelled', ['error' => $this->error]);
            return $this->bridge('oauth_error', 'Google sign-in was cancelled.');
        }

        if (empty($this->code) || empty($this->state)) {
            return $this->bridge('oauth_error', 'Missing code or state from Google.');
        }

        $statePayload = $this->jwtHandler->verifyToken($this->state);
        if ($statePayload === false || ($statePayload['purpose'] ?? null) !== 'oauth_state') {
            log_error('GoogleOAuthCallbackRoute: invalid or expired state token');
            return $this->bridge('oauth_error', 'This sign-in link expired or is invalid. Please try again.');
        }

        $this->openerOrigin = is_string($statePayload['opener_origin'] ?? null) ? (string) $statePayload['opener_origin'] : null;

        // The state was bound to an opener that is no longer (or was never) allowed: do not exchange the code or
        // mint anything - there is nowhere safe to deliver the result. Show the configuration problem instead.
        if ($this->openerOrigin !== null && !in_array($this->openerOrigin, OpenerOrigins::allowed($this->allowedOrigins), true)) {
            return $this->bridge('oauth_error', 'Sign-in is not configured for this site.');
        }

        $client = new GoogleClient();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);

        try {
            $token = $client->fetchAccessTokenWithAuthCode($this->code);
        } catch (\Exception $e) {
            log_error('GoogleOAuthCallbackRoute: code exchange failed: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
            return $this->bridge('oauth_error', 'Could not complete Google sign-in.');
        }

        if (isset($token['error']) || empty($token['id_token'])) {
            log_error('GoogleOAuthCallbackRoute: token exchange returned no id_token', ['token' => $token]);
            return $this->bridge('oauth_error', 'Could not complete Google sign-in.');
        }

        // verifyIdToken checks aud against the client_id already set above,
        // plus signature (Google's live JWKS), iss, and exp — same checks
        // GoogleOauthRoute.php.template performs on a client-obtained credential.
        $payload = $client->verifyIdToken($token['id_token']);

        if (!$payload) {
            log_error('GoogleOAuthCallbackRoute: id_token verification failed');
            return $this->bridge('oauth_error', 'Could not verify your Google identity.');
        }

        if (empty($payload['email_verified'])) {
            log_warning('GoogleOAuthCallbackRoute: unverified Google email rejected');
            return $this->bridge('oauth_error', 'Your Google account email is not verified.');
        }

        $profile = [
            'sub' => $payload['sub'],
            'email' => $payload['email'] ?? null,
            'email_verified' => (bool) ($payload['email_verified'] ?? false),
            'name' => $payload['name'] ?? null,
            'picture' => $payload['picture'] ?? null,
        ];

        return $this->resolveAndMintTokens($profile);
    }

    /**
     * Resolver call -> LoginUser (the R1 chokepoint) -> token minting ->
     * postMessage bridge. Extracted from process() so it is independently
     * unit-testable without needing a real Google network round-trip (the
     * network exchange happens in process() before this is called; a test
     * can invoke this directly with a hand-built $profile — see
     * tests/Unit/GoogleOAuthBuiltinTest.php, matrix C11).
     *
     * Every path here that can fail (resolver throws, or LoginUser::create()
     * throws on an empty/misnamed email or display_name) is caught and
     * bridged as `oauth_error` — NO token is ever minted from a
     * partially-built or contract-violating login user.
     *
     * @param array{sub?:string,email:?string,email_verified:bool,name:?string,picture:?string} $profile
     */
    public function resolveAndMintTokens(array $profile): HtmlResponse
    {
        try {
            $resolved = $this->userResolver->resolve($profile);
            $loginUser = LoginUser::fromResolverArray($resolved, $profile);
        } catch (\Exception $e) {
            log_error('GoogleOAuthCallbackRoute: user resolver failed: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
            return $this->bridge('oauth_error', 'Could not create or update your account.');
        }

        $userClaims = $loginUser->toArray();

        if ($this->issuer !== null) {
            // Persisted path: the refresh token and its row are created together, or not at all.
            try {
                $issued = $this->issuer->issueSession($userClaims, TokenClaims::PURPOSE_AUTHENTICATION);
            } catch (\Throwable $e) {
                log_error('GoogleOAuthCallbackRoute: could not issue a persisted session: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
                return $this->bridge('oauth_error', 'Sign-in is temporarily unavailable. Please try again in a moment.');
            }
            $accessToken = $issued->accessToken;
            $refreshToken = $issued->refreshToken;
        } else {
            $env = Env::get_instance();
            $accessToken = $this->jwtHandler->generateToken($userClaims, $env->JWT_ACCESS_TOKEN_EXPIRY ?? 900, 'access');
            $refreshToken = $this->jwtHandler->generateToken($userClaims, $env->JWT_REFRESH_TOKEN_EXPIRY ?? 15552000, 'refresh');
            self::noticeUnpersisted();
        }

        log_info('GoogleOAuthCallbackRoute: sign-in complete', ['user_id' => $loginUser->getUserId()]);

        return $this->bridge('oauth_success', null, [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'user' => $userClaims,
        ]);
    }

    /** This refresh token has no row, so any RefreshTokenMiddleware gate will refuse it. Rate-limited, never throws. */
    private static function noticeUnpersisted(): void
    {
        \StoneScriptPHP\Support\DeprecationNotice::emit(
            'oauth:unpersisted-refresh-token',
            'StoneScriptPHP: the builtin OAuth callback minted a refresh token that is NOT persisted. A refresh gate '
            . '(RefreshTokenMiddleware) will reject it, and it cannot be revoked. This is DEPRECATED: set '
            . 'REFRESH_TOKEN_STORE=postgres (or auth.refresh_tokens.store) and run `php stone gateway:migrate-vendor-main` first. '
            . 'Persistence becomes the default in the next major version.'
        );
    }

    /**
     * Resolve the sign-in in the opener via postMessage. NEVER with targetOrigin '*': the data (including both
     * tokens) goes only to allowed origins - the single origin the state was bound to when there is one, else
     * every allowed origin (the browser delivers to the one that equals the opener's real origin and drops the rest).
     * An opener that is not allowed receives nothing; with no allowed origin configured nothing is posted at all.
     */
    private function bridge(string $type, ?string $message, array $extra = []): HtmlResponse
    {
        $allowed = OpenerOrigins::allowed($this->allowedOrigins);
        if ($this->openerOrigin !== null && in_array($this->openerOrigin, $allowed, true)) {
            $targets = [$this->openerOrigin];
        } elseif ($this->openerOrigin !== null) {
            $targets = []; // bound to an origin that is not allowed: deliver nowhere
        } else {
            $targets = $allowed;
        }
        if ($targets === []) {
            // Nothing can safely receive the result. No data (and so no tokens) is put in the page; the popup shows a
            // visible message naming the configuration problem. The opener-side library times out with its own message.
            log_error('GoogleOAuthCallbackRoute: sign-in result NOT delivered - no allowed opener origin for this flow (check ALLOWED_ORIGINS with `php stone auth:check-origins`)');
            return OAuthConfigErrorPage::response(
                'Sign-in could not be completed.',
                'This site is not listed in the server setting ALLOWED_ORIGINS, so the sign-in result cannot be handed back to it. The site administrator must add it.',
                403
            );
        }
        $data = array_merge(['type' => $type], $message !== null ? ['message' => $message] : [], $extra);
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $targetsJson = json_encode($targets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $html = <<<HTML
<!DOCTYPE html>
<html><head><title>Signing you in&hellip;</title></head><body><script>
(function() {
  var data = {$json};
  var targets = {$targetsJson};
  if (window.opener) {
    targets.forEach(function(o) { try { window.opener.postMessage(data, o); } catch (e) {} });
    document.body.innerText = 'Sign-in finished. You can close this window.';
    window.close();
  } else {
    document.body.innerText = 'Sign-in finished, but this window was not opened by the site you signed in from. You can close it.';
  }
})();
</script></body></html>
HTML;

        return new HtmlResponse($html);
    }
}
