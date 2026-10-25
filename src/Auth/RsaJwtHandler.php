<?php

namespace StoneScriptPHP\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use StoneScriptPHP\Env;

/**
 * RSA JWT Handler
 *
 * Advanced JWT implementation using RSA public/private key pairs (RS256).
 * More secure for distributed systems and microservices.
 *
 * Requirements:
 * - Generate RSA keypair: php generate-openssl-keypair.sh
 * - JWT_PRIVATE_KEY_PATH and JWT_PUBLIC_KEY_PATH in .env
 *
 * Key generation:
 *   $ ssh-keygen -t rsa -m pkcs8 -f keys/jwt-private.pem
 *   $ chmod 600 keys/jwt-private.pem
 *
 * Example usage:
 *   $handler = new RsaJwtHandler();
 *   $token = $handler->generateToken(['user_id' => 123]);
 *   $payload = $handler->verifyToken($token);
 */
class RsaJwtHandler implements JwtHandlerInterface, StrictTokenVerifier
{
    private const ALGORITHM = 'RS256';

    /**
     * Generate a JWT token using RSA private key
     *
     * @param array $payload Data to encode in the token
     * @param int $expirySeconds Token expiry in seconds (default: 900 = 15 minutes)
     * @param string $tokenType 'access' or 'refresh' — selects the expiry AND is stamped
     *   as the first-class `type` claim (TokenClaims::CLAIM_TYPE). Distinct from the
     *   `token_type` (card/platform) CLASS claim — do not conflate.
     * @param string $purpose 'authentication' (identity/auth-token) or 'authorization'
     *   (API token) — stamped as the first-class `purpose` claim. Defaults to
     *   authentication because RsaJwtHandler is the local identity handler; API-token mint
     *   sites pass 'authorization'.
     * @return string JWT token
     * @throws \RuntimeException if private key cannot be loaded
     */
    /**
     * @param LocalSigningIdentity|null $identity Issuer + keys. Null (the normal case) = the process identity
     *   Application::run() established, else the environment (JWT_ISSUER, JWT_PRIVATE_KEY_PATH, ...).
     */
    public function __construct(private ?LocalSigningIdentity $identity = null)
    {
    }

    private function identity(): LocalSigningIdentity
    {
        return $this->identity ?? LocalSigningIdentity::current();
    }

    public function generateToken(
        array $payload,
        ?int $expirySeconds = null,
        string $tokenType = TokenClaims::TYPE_ACCESS,
        string $purpose = TokenClaims::PURPOSE_AUTHENTICATION
    ): string {
        $identity = $this->identity();
        $privateKeyPath = $this->getPrivateKeyPath();
        $privateKeyContent = file_get_contents($privateKeyPath);

        if ($privateKeyContent === false) {
            throw new \RuntimeException("Cannot read private key file: $privateKeyPath");
        }

        // Support passphrase-protected keys
        $env = Env::get_instance();
        $passphrase = $identity->privateKeyPassphrase;
        $privateKey = $passphrase
            ? openssl_pkey_get_private($privateKeyContent, $passphrase)
            : openssl_pkey_get_private($privateKeyContent);

        if (!$privateKey) {
            throw new \RuntimeException('Unable to load private key for JWT signing. Check JWT_PRIVATE_KEY_PASSPHRASE if using encrypted key.');
        }

        // Use custom expiry from .env if not provided
        if ($expirySeconds === null) {
            $expirySeconds = $tokenType === 'refresh'
                ? ($env->JWT_REFRESH_TOKEN_EXPIRY ?? 15552000)  // 180 days
                : ($env->JWT_ACCESS_TOKEN_EXPIRY ?? 900);        // 15 minutes
        }

        $issuedAt = time();
        $expire = $issuedAt + $expirySeconds;

        // JWT_ISSUER MUST be set explicitly — defaulting to 'example.com' is a security trap:
        // an API token minted with iss=example.com will only pass verification if the server still
        // has JWT_ISSUER=example.com, creating a false sense of working auth that breaks the
        // moment the issuer is corrected. Fail loud so the misconfiguration is caught at token
        // generation time rather than as an intermittent 401 in production.
        $issuer = $identity->issuer;
        if (empty($issuer)) {
            throw new \RuntimeException(
                'JWT_ISSUER is not set or empty. Set JWT_ISSUER in .env to the canonical API URL '
                . 'for this platform (e.g. https://api.exampleapp.in). This value is stamped as '
                . "the 'iss' claim on every JWT this platform mints. Defaulting to a placeholder "
                . 'silently produces tokens that fail issuer validation after any issuer correction.'
            );
        }

        // the auth contract: custom claims at top level alongside standard JWT claims.
        // Spreading $payload after the standard claims means any claim in $payload
        // (e.g. 'exp') would override the standard value — callers should not include
        // reserved JWT fields in $payload.
        // Stamp the typed token claims (type/purpose) as first-class framework
        // defaults. They sit in the base array so an explicit $payload value still
        // wins (consistent with the long-standing payload-spread-last convention,
        // and required by internal callers such as the OAuth state token, which
        // legitimately reuses the `purpose` key for its own state marker).
        $data = array_merge([
            'iss'                      => $issuer,
            'iat'                      => $issuedAt,
            'exp'                      => $expire,
            TokenClaims::CLAIM_TYPE    => $tokenType,
            TokenClaims::CLAIM_PURPOSE => $purpose,
        ], $payload);

        return JWT::encode($data, $privateKey, self::ALGORITHM);
    }

    /**
     * Verify and decode a JWT token using RSA public key
     *
     * Every failure is `false`, including "the public key cannot be read" (logged). Use {@see verifyTokenStrict()} where the
     * two must be told apart.
     *
     * @param string $token JWT token to verify
     * @param bool $verifyIssuer Whether to verify the issuer claim
     * @return array|false Decoded payload on success, false on failure
     */
    public function verifyToken(string $token, bool $verifyIssuer = true): array|false
    {
        try {
            return $this->verifyTokenStrict($token, $verifyIssuer);
        } catch (TokenVerifierUnavailableException $e) {
            log_error('JWT verification unavailable: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
            return false;
        }
    }

    /**
     * Like {@see verifyToken()}, but a verifying key that is missing, unreadable or not a valid public key throws
     * {@see TokenVerifierUnavailableException}: that is a server problem, not a verdict on the token.
     *
     * @return array|false
     * @throws TokenVerifierUnavailableException
     */
    public function verifyTokenStrict(string $token, bool $verifyIssuer = true): array|false
    {
        $identity = $this->identity();
        if ($identity->publicKeyPem === null && empty($identity->publicKeyPath)) {
            throw new TokenVerifierUnavailableException(
                'JWT_PUBLIC_KEY_PATH environment variable is not set. Please add JWT_PUBLIC_KEY_PATH to your .env file.'
            );
        }
        $publicKey = $identity->publicKeyContents();
        if ($publicKey === false || $publicKey === '') {
            throw new TokenVerifierUnavailableException('Cannot read public key file: ' . $identity->publicKeyPath);
        }
        if (openssl_pkey_get_public($publicKey) === false) {
            throw new TokenVerifierUnavailableException('The configured JWT public key is not a valid public key.');
        }

        try {
            $decoded = JWT::decode($token, new Key($publicKey, self::ALGORITHM));

            // Optionally verify issuer.
            // If JWT_ISSUER is not set, skip issuer verification rather than comparing
            // against a placeholder - this prevents false 'example.com' matches and
            // lets the HybridApiTokenJwtHandler's RSA-then-JWKS chain return false cleanly
            // so the JWKS fallback can try the token instead.
            if ($verifyIssuer) {
                $expectedIssuer = $identity->issuer;
                if (!empty($expectedIssuer) && isset($decoded->iss) && $decoded->iss !== $expectedIssuer) {
                    log_warning("JWT issuer mismatch: expected '$expectedIssuer', got '{$decoded->iss}'");
                    return false;
                }
            }

            // the auth contract: claims are at top level. Return all claims minus
            // the standard JWT fields that are framework internals (iss, iat, exp, etc.).
            $claims = (array) $decoded;
            unset($claims['iss'], $claims['iat'], $claims['exp'], $claims['aud'], $claims['nbf']);
            return $claims;
        } catch (\Firebase\JWT\ExpiredException $e) {
            log_debug('JWT token expired: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
            return false;
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            log_warning('JWT signature invalid: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
            return false;
        } catch (\Exception $e) {
            log_warning('JWT verification failed: ' . \StoneScriptPHP\Persistence\LogSanitizer::describe($e));
            return false;
        }
    }

    /**
     * Get private key path from environment
     *
     * @return string
     * @throws \RuntimeException if JWT_PRIVATE_KEY_PATH is not set
     */
    private function getPrivateKeyPath(): string
    {
        $path = $this->identity()->privateKeyPath;

        if (empty($path)) {
            throw new \RuntimeException(
                'JWT_PRIVATE_KEY_PATH environment variable is not set. ' .
                'Please add JWT_PRIVATE_KEY_PATH to your .env file.'
            );
        }

        return LocalSigningIdentity::absolute($path);
    }
}
