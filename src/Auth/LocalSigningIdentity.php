<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth;

use StoneScriptPHP\Env;

/**
 * The ONE description of "who this API is as a token issuer": the issuer string it stamps, the private key it signs
 * with and the public key that verifies those signatures.
 *
 * Everything that mints or verifies this API's own tokens reads it from here - RsaJwtHandler (login / refresh /
 * JwtAuthMiddleware), HybridApiTokenJwtHandler, AuthMiddlewareRegistrar::forMode() and, through ExternalAuthConfig,
 * ExchangeRoute - so overriding the signing key or issuer (`signing_private_key_path`, `signing_issuer`) moves the whole
 * chain together and the verifiers can never disagree with the signer.
 *
 * Source of truth, in order: the process default set by Application::run() (from ExternalAuthConfig in external/hybrid
 * mode, so `auth` / `auth.features` overrides apply) > the environment (JWT_ISSUER, JWT_PRIVATE_KEY_PATH,
 * JWT_PRIVATE_KEY_PASSPHRASE, JWT_PUBLIC_KEY_PATH).
 */
final class LocalSigningIdentity
{
    private static ?self $default = null;

    public function __construct(
        public readonly string $issuer,
        public readonly string $privateKeyPath,
        public readonly ?string $privateKeyPassphrase,
        public readonly string $publicKeyPath,
        /** When set, verification uses this PEM instead of reading $publicKeyPath (derived from an overridden private key). */
        public readonly ?string $publicKeyPem = null,
    ) {
    }

    public static function fromEnv(Env $env): self
    {
        return new self(
            (string) $env->JWT_ISSUER,
            (string) $env->JWT_PRIVATE_KEY_PATH,
            $env->JWT_PRIVATE_KEY_PASSPHRASE ?? null,
            (string) $env->JWT_PUBLIC_KEY_PATH
        );
    }

    /**
     * From the config ExchangeRoute signs with. Equal to {@see fromEnv()} unless the signing key or issuer is
     * overridden; an overridden private key's public half is derived from that key.
     */
    public static function fromExternalConfig(\StoneScriptPHP\Auth\ExternalAuth\ExternalAuthConfig $config, Env $env): self
    {
        $pem = null;
        if ($config->signingPrivateKeyPath !== (string) $env->JWT_PRIVATE_KEY_PATH) {
            $pem = self::publicPemFromPrivateKey($config->signingPrivateKeyPath, $config->signingPrivateKeyPassphrase);
        }
        return new self(
            $config->signingIssuer,
            $config->signingPrivateKeyPath,
            $config->signingPrivateKeyPassphrase,
            (string) $env->JWT_PUBLIC_KEY_PATH,
            $pem
        );
    }

    /** Set (or clear with null) the process-wide identity. Called by Application::run(). */
    public static function setDefault(?self $identity): void
    {
        self::$default = $identity;
    }

    /** @internal */
    public static function resetDefault(): void
    {
        self::$default = null;
    }

    /** The process default if one was set, else the environment (read at call time). */
    public static function current(): self
    {
        return self::$default ?? self::fromEnv(Env::get_instance());
    }

    /** Resolve a possibly project-relative path against ROOT_PATH. */
    public static function absolute(string $path): string
    {
        if ($path === '' || $path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }
        if (!defined('ROOT_PATH')) {
            throw new \RuntimeException("Cannot resolve the relative key path '{$path}': ROOT_PATH is not defined. Define ROOT_PATH or use an absolute path.");
        }
        return ROOT_PATH . preg_replace('#^\./#', '', $path);
    }

    /** Public key PEM that verifies tokens signed by this identity, or false when it cannot be read. */
    public function publicKeyContents(): string|false
    {
        if ($this->publicKeyPem !== null && $this->publicKeyPem !== '') {
            return $this->publicKeyPem;
        }
        return @file_get_contents(self::absolute($this->publicKeyPath));
    }

    public static function publicPemFromPrivateKey(string $privateKeyPath, ?string $passphrase): string
    {
        $abs = self::absolute($privateKeyPath);
        $pem = is_file($abs) ? file_get_contents($abs) : false;
        $key = $pem === false ? false : openssl_pkey_get_private($pem, $passphrase ?? '');
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || !isset($details['key'])) {
            throw new \RuntimeException("Cannot read the signing private key '{$privateKeyPath}' (signing_private_key_path) to derive the public key tokens are verified with.");
        }
        return $details['key'];
    }
}
