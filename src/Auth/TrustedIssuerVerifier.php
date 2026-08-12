<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * TrustedIssuerVerifier — mandatory, issuer-selects-the-key JWT verification.
 *
 * ## Why this exists
 *
 * The single-key `RsaJwtHandler::verifyToken()` performs an `iss` **equality**
 * check (and skips it entirely when `JWT_ISSUER` is empty). It has NO notion of
 * *which* issuer is allowed to mint *which* class of token, and it verifies every
 * token its one public key signed. The `MultiAuthJwtValidator` DOES select a key
 * by `iss` — but only on the JWKS (federated-identity) path.
 *
 * This verifier unifies both into a single **trusted-issuer → key** map so that:
 *
 *   1. `iss` verification is MANDATORY — a token with no `iss`, or an `iss` not in
 *      the trusted set, is rejected outright (no "skip when unset" escape hatch).
 *   2. `iss` SELECTS the verification key. A token claiming `iss=X` is verified
 *      with X's key ONLY. Presenting an `iss=X` token that was actually signed by
 *      Y's key fails the signature check — closing the issuer-substitution hole.
 *
 * ## Configuration
 *
 * Keyed by the exact issuer string. Each entry declares how that issuer's tokens
 * are verified — a local RSA public key, or a federated JWKS endpoint:
 *
 * ```php
 * $verifier = new TrustedIssuerVerifier([
 *     // Builtin/standalone platform: identity AND API token share one local key + iss.
 *     'https://api.exampleapp.in' => [
 *         'kind'       => 'local',
 *         'public_key' => file_get_contents('/keys/jwt-public.pem'),
 *         // or 'public_key_path' => '/keys/jwt-public.pem',
 *         'audience'   => null,          // optional strict audience check
 *     ],
 *     // Federated identity provider: auth tokens verified via JWKS (different key!).
 *     'https://auth.example.com' => [
 *         'kind'      => 'jwks',
 *         'jwks_url'  => 'https://auth.example.com/auth/jwks',
 *         'audience'  => 'my-api',
 *         'cache_ttl' => 3600,
 *         // Alternatively, for pinned/offline keys:
 *         // 'jwks_keys' => Firebase\JWT\JWK::parseKeySet($jwksArray),
 *     ],
 * ]);
 * ```
 *
 * This is the typed evolution of `MultiAuthJwtValidator.auth_servers[]`: that
 * structure expresses "acceptable JWKS issuers"; this one adds the local-key
 * issuers and makes the whole set the single source of "which issuer, which key".
 *
 * @package StoneScriptPHP\Auth
 * @since   6.2.0
 */
class TrustedIssuerVerifier
{
    private const DEFAULT_ALGORITHM = 'RS256';

    /** @var array<string, array<string, mixed>> issuer => entry */
    private array $issuers;

    private ?MultiAuthJwtValidator $jwksValidator = null;

    /**
     * @param array<string, array<string, mixed>> $issuers issuer => verification config
     * @param string|null $jwksCacheDir Cache dir passed through to the JWKS validator
     */
    public function __construct(array $issuers, private readonly ?string $jwksCacheDir = null)
    {
        if (empty($issuers)) {
            throw new \InvalidArgumentException(
                'TrustedIssuerVerifier requires at least one trusted issuer. An empty '
                . 'trusted-issuer set would reject every token — configure the platform\'s '
                . 'own issuer (and any federated identity issuers) explicitly.'
            );
        }

        foreach ($issuers as $issuer => $entry) {
            if (!is_string($issuer) || $issuer === '') {
                throw new \InvalidArgumentException('Trusted-issuer keys must be non-empty issuer strings.');
            }
            $kind = $entry['kind'] ?? null;
            if (!in_array($kind, ['local', 'jwks'], true)) {
                throw new \InvalidArgumentException(
                    "Trusted issuer '$issuer' must declare 'kind' => 'local' or 'jwks', got "
                    . var_export($kind, true) . '.'
                );
            }
        }

        $this->issuers = $issuers;
    }

    /**
     * Verify a token: mandatory `iss`, key selected by `iss`, signature + exp.
     *
     * Does NOT check `type`/`purpose` — those are strict-equality assertions owned
     * by the access/refresh middleware (they read `route.access` + the token's type).
     * This method's sole job is the mode/federation-dependent iss→key verification.
     *
     * @param string $token Raw JWT
     * @return array<string, mixed>|null Decoded claims (incl. iss) on success, null on any failure
     */
    public function verify(string $token): ?array
    {
        $issuer = $this->peekIssuer($token);
        if ($issuer === null) {
            error_log('TrustedIssuerVerifier: token has no readable iss claim — rejected.');
            return null;
        }

        $entry = $this->issuers[$issuer] ?? null;
        if ($entry === null) {
            // Mandatory: an untrusted issuer is a hard reject — never fall back to
            // "verify anyway with whatever key we have".
            error_log("TrustedIssuerVerifier: untrusted issuer '$issuer' — rejected.");
            return null;
        }

        try {
            if ($entry['kind'] === 'local') {
                $claims = $this->verifyLocal($token, $entry);
            } else {
                $claims = $this->verifyJwks($token, $issuer, $entry);
            }
        } catch (\Firebase\JWT\ExpiredException $e) {
            error_log('TrustedIssuerVerifier: token expired — ' . $e->getMessage());
            return null;
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            // This is the issuer-substitution rejection: iss selected a key the
            // token was NOT signed with.
            error_log("TrustedIssuerVerifier: signature invalid for iss '$issuer' — rejected.");
            return null;
        } catch (\Throwable $e) {
            error_log('TrustedIssuerVerifier: verification failed — ' . $e->getMessage());
            return null;
        }

        if ($claims === null) {
            return null;
        }

        // Defence in depth: the decoded iss MUST equal the issuer whose key we used.
        if (($claims['iss'] ?? null) !== $issuer) {
            error_log('TrustedIssuerVerifier: decoded iss does not match key-selecting iss — rejected.');
            return null;
        }

        // Optional strict audience check.
        $expectedAud = $entry['audience'] ?? null;
        if ($expectedAud !== null) {
            $aud = $claims['aud'] ?? null;
            $ok = is_array($aud) ? in_array($expectedAud, $aud, true) : $aud === $expectedAud;
            if (!$ok) {
                error_log("TrustedIssuerVerifier: audience mismatch for iss '$issuer' — rejected.");
                return null;
            }
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function verifyLocal(string $token, array $entry): array
    {
        $publicKey = $entry['public_key'] ?? null;
        if ($publicKey === null && !empty($entry['public_key_path'])) {
            $publicKey = @file_get_contents($entry['public_key_path']);
            if ($publicKey === false) {
                throw new \RuntimeException("Cannot read public key: {$entry['public_key_path']}");
            }
        }
        if (empty($publicKey)) {
            throw new \InvalidArgumentException("Local issuer entry needs 'public_key' or 'public_key_path'.");
        }

        $algorithm = $entry['algorithm'] ?? self::DEFAULT_ALGORITHM;
        $decoded = JWT::decode($token, new Key($publicKey, $algorithm));
        return (array) $decoded;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    private function verifyJwks(string $token, string $issuer, array $entry): ?array
    {
        // Offline / pinned-key path: keys supplied directly.
        if (!empty($entry['jwks_keys'])) {
            $decoded = JWT::decode($token, $entry['jwks_keys']);
            return (array) $decoded;
        }

        // Prod path: delegate to MultiAuthJwtValidator (iss→jwks_url fetch + cache).
        // It already selects the key by iss and rejects unknown issuers.
        $validator = $this->jwksValidator ??= $this->buildJwksValidator();
        $claims = $validator->validateJWT($token);
        if ($claims === null) {
            return null;
        }
        // MultiAuthJwtValidator adds a synthetic 'issuer_type' — drop it; callers key on iss.
        unset($claims['issuer_type']);
        return $claims;
    }

    private function buildJwksValidator(): MultiAuthJwtValidator
    {
        $authServers = [];
        foreach ($this->issuers as $issuer => $entry) {
            if (($entry['kind'] ?? null) !== 'jwks' || empty($entry['jwks_url'])) {
                continue;
            }
            $authServers[$issuer] = array_filter([
                'issuer'        => $issuer,
                'jwks_url'      => $entry['jwks_url'],
                'audience'      => $entry['audience'] ?? null,
                'cache_ttl'     => $entry['cache_ttl'] ?? null,
                'max_stale_ttl' => $entry['max_stale_ttl'] ?? null,
            ], fn($v) => $v !== null);
        }
        return new MultiAuthJwtValidator($authServers, $this->jwksCacheDir);
    }

    /**
     * Read the `iss` claim WITHOUT verifying — used only to select the key. The
     * selected key then verifies the signature, so a forged iss cannot help an
     * attacker: it just routes the token to a key it was not signed with.
     */
    private function peekIssuer(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        try {
            $payload = JWT::jsonDecode(JWT::urlsafeB64Decode($parts[1]));
        } catch (\Throwable $e) {
            return null;
        }
        $iss = $payload->iss ?? null;
        return (is_string($iss) && $iss !== '') ? $iss : null;
    }

    /**
     * @return array<string> The configured trusted issuers.
     */
    public function trustedIssuers(): array
    {
        return array_keys($this->issuers);
    }
}
