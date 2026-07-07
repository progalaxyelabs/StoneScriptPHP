<?php

namespace StoneScriptPHP\Auth\BuiltinOAuth;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\HtmlResponse;
use StoneScriptPHP\Env;
use StoneScriptPHP\Auth\JwtHandlerInterface;
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

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly JwtHandlerInterface $jwtHandler,
        private readonly GoogleOAuthUserResolver $userResolver,
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

        $client = new GoogleClient();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);

        try {
            $token = $client->fetchAccessTokenWithAuthCode($this->code);
        } catch (\Exception $e) {
            log_error('GoogleOAuthCallbackRoute: code exchange failed: ' . $e->getMessage());
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
            log_warning('GoogleOAuthCallbackRoute: unverified Google email rejected', ['email' => $payload['email'] ?? null]);
            return $this->bridge('oauth_error', 'Your Google account email is not verified.');
        }

        $profile = [
            'sub' => $payload['sub'],
            'email' => $payload['email'] ?? null,
            'email_verified' => (bool) ($payload['email_verified'] ?? false),
            'name' => $payload['name'] ?? null,
            'picture' => $payload['picture'] ?? null,
        ];

        try {
            $userClaims = $this->userResolver->resolve($profile);
        } catch (\Exception $e) {
            log_error('GoogleOAuthCallbackRoute: user resolver failed: ' . $e->getMessage());
            return $this->bridge('oauth_error', 'Could not create or update your account.');
        }

        $env = Env::get_instance();
        $accessToken = $this->jwtHandler->generateToken($userClaims, $env->JWT_ACCESS_TOKEN_EXPIRY ?? 900, 'access');
        $refreshToken = $this->jwtHandler->generateToken($userClaims, $env->JWT_REFRESH_TOKEN_EXPIRY ?? 15552000, 'refresh');

        log_info('GoogleOAuthCallbackRoute: sign-in complete', ['email' => $profile['email']]);

        return $this->bridge('oauth_success', null, [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'user' => $userClaims,
        ]);
    }

    private function bridge(string $type, ?string $message, array $extra = []): HtmlResponse
    {
        $data = array_merge(['type' => $type], $message !== null ? ['message' => $message] : [], $extra);
        $json = json_encode($data);

        $html = <<<HTML
<!DOCTYPE html>
<html><head><title>Signing you in&hellip;</title></head><body><script>
(function() {
  var data = {$json};
  if (window.opener) {
    window.opener.postMessage(data, '*');
    window.close();
  } else {
    document.body.innerText = 'Sign-in complete. You can close this window.';
  }
})();
</script></body></html>
HTML;

        return new HtmlResponse($html);
    }
}
