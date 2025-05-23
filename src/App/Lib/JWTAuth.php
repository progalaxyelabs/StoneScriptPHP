<?php

namespace App\Lib;

use App\Env;
use App\Models\MyTokenClaims;
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;
use \Firebase\JWT\SignatureInvalidException;
use \Firebase\JWT\BeforeValidException;
use \Firebase\JWT\ExpiredException;
use DomainException;
use InvalidArgumentException;
use UnexpectedValueException;

class JWTAuth
{
    const PEM_FILE_PATH = ROOT_PATH . 'stone-script-php.pem';
    const PUB_FILE_PATH = ROOT_PATH . 'stone-script-php.pub';
    const KEY_TYPE = 'ed25519';
    const JWT_ALGORITHM = 'RS256';
    const REFRESH_TOKEN_COOKIE_NAME = 'Refresh-Token';

    private static ?JWTAuth $_instance = null;

    private string $access_token = '';
    private string $refresh_token = '';
    private MyTokenClaims $claims;

    private function __construct()
    {
        $ok = $this->readAccessTokenFromAuthorizationHeader();
        $this->claims = $ok ? $this->decodeToken($this->access_token) : null;
        $this->readRefreshTokenFromCookie();
    }

    public static function getInstance(): JWTAuth
    {
        if (self::$_instance) {
            return self::$_instance;
        }

        self::$_instance = new JWTAuth();
        return self::$_instance;
    }

    private function readAccessTokenFromAuthorizationHeader(): bool
    {
        if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
            log_debug('JWTAuth - no http_authorization header');
            return '';
        }

        $authorization_header = htmlspecialchars($_SERVER['HTTP_AUTHORIZATION']);

        $token = substr($authorization_header, 7); // remove 'Bearer ' prefix
        $this->access_token = ($token === false) ? '' : $token;
        return ($this->access_token !== '');
    }

    private function readRefreshTokenFromCookie(): bool
    {
        $this->refresh_token = htmlspecialchars($_COOKIE[self::REFRESH_TOKEN_COOKIE_NAME]);
        return ($this->refresh_token !== '');
    }

    private function decodeToken(string $token): MyTokenClaims
    {
        $public_key = file_get_contents(self::PUB_FILE_PATH);
        if ($public_key === false) {
            log_error(__METHOD__ . ' - no public key file');
            return new MyTokenClaims();
        }

        try {
            $decoded_token = JWT::decode($token, new Key($public_key, self::JWT_ALGORITHM));
            $claims = MyTokenClaims::fromDecodedToken($decoded_token);
            return $claims;
        } catch (InvalidArgumentException $e) {
            log_error(__METHOD__ . ' - invalid argument exception ' . $e->getMessage());
        } catch (DomainException $e) {
            log_error(__METHOD__ . ' - domain exception ' . $e->getMessage());
        } catch (SignatureInvalidException $e) {
            log_error(__METHOD__ . ' - signature invalid exception ' . $e->getMessage());
        } catch (BeforeValidException $e) {
            log_error(__METHOD__ . ' - before valid exception ' . $e->getMessage());
        } catch (ExpiredException $e) {
            log_error(__METHOD__ . ' - expired exception ' . $e->getMessage());
            // if ($allow_expiry) {
            //     list($headerStr, $payloadStr, $signatureStr) = explode('.', $token);
            //     $payload = json_decode(base64_decode($payloadStr));
            //     $claims = MyTokenClaims::fromDecodedToken($payload);
            //     return $claims;
            // }
        } catch (UnexpectedValueException $e) {
            log_error(__METHOD__ . ' - unexpected value exception ' . $e->getMessage());
        }

        return new MyTokenClaims();
    }

    public function createTokens(int $user_id, bool $generate_refresh_token = true): bool
    {
        // generate public private key pair using the below commands 
        // so that the openssl_pkey_get_private to work properly
        // $ ssh-keygen -t rsa -m pkcs8
        // enter file name as key.pem
        // give passphrase as 12345678
        // confirm passphrase again 12345678
        // two files will be generated - key.pem, key.pem.pub
        // rename key.pem.pub to key.pub
        // $ mv key.pem.pub key.pub
        // give read permissions for the key.pem file on some linux distros
        // $ chmod go+r key.pem

        $pass_phrase = '';
        $private_key = openssl_pkey_get_private(
            file_get_contents(self::PEM_FILE_PATH),
            $pass_phrase
        );
        if (!$private_key) {
            log_error('user signin - unable to read the private key file using the passphrase');
            return false;
        }

        $now = new \DateTimeImmutable();
        $access_issued_at = $now;
        $access_expires_at = $access_issued_at->modify('+15 minutes');
        include CONFIG_PATH . 'app.php';
        $access_payload = [
            'iss' => Env::$OAUTH_APP_DOMAIN,
            'iat' => $access_issued_at->getTimestamp(),
            'exp' => $access_expires_at->getTimestamp(),
            'user_id' => $user_id
        ];
        $this->access_token = JWT::encode($access_payload, $private_key, self::JWT_ALGORITHM);

        $this->refresh_token = '';
        if ($generate_refresh_token) {
            $refresh_issued_at = $now;
            $refresh_expires_at = $refresh_issued_at->modify('+180 days');
            $refresh_payload = [
                'iss' => Env::$OAUTH_APP_DOMAIN,
                'iat' => $refresh_issued_at->getTimestamp(),
                'exp' => $refresh_expires_at->getTimestamp(),
                'user_id' => $user_id
            ];
            $this->refresh_token = JWT::encode($refresh_payload, $private_key, self::JWT_ALGORITHM);
        }

        return true;
    }

    public function getAccessToken(): string
    {
        return $this->access_token;
    }

    public function setRefreshTokenCookie(): void
    {
        $cookie_options = [
            'expires' => time() + 60 * 60 * 24 * 30,
            'path' => '/auth/refresh/',
            'domain' => Env::$OAUTH_APP_DOMAIN, // leading dot for compatibility or use subdomain
            'secure' => true,     // or false
            'httponly' => true,    // or false
        ];
        setcookie(self::REFRESH_TOKEN_COOKIE_NAME, $this->refresh_token, $cookie_options);
    }

    public function userIdFromRefreshToken(): int
    {
        return $this->decodeToken($this->refresh_token)->user_id;
    }
}
