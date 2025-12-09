<?php

namespace Framework\Lib\Auth;

use Framework\Lib\User\MyTokenClaims;
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
    const PEM_FILE_PATH = ROOT_PATH .'stone-script-php.pem';
    const PUB_FILE_PATH = ROOT_PATH .'stone-script-php.pub';
    const KEY_TYPE = 'ed25519';
    const JWT_ALGORITHM = 'RS256';

    public function __construct()
    {
    }

    public function claimsFromAuthorizationHeader($request)
    {
        $token = $this->tokenFromAuthorizationHeader($request);
        if (!$token) {
            return null;
        }

        $claims = $this->decodeToken($token);
        if (!$claims) {
            return null;
        }

        return $claims;
    }

    protected function tokenFromAuthorizationHeader($request): string
    {
        $authorization_header = $request->getServer('HTTP_AUTHORIZATION');
        if (!$authorization_header) {
            log_debug('identifyUserSignedIn - no http_authorization header');
            return '';
        }

        $token = substr($authorization_header, 7); // remove 'Bearer ' prefix
        return $token;
    }

    public function decodeToken($token, $allow_expiry = false): ?MyTokenClaims
    {
        $public_key = file_get_contents(self::PUB_FILE_PATH);
        if ($public_key === false) {
            log_error(__METHOD__ . ' - no public key file');
            return null;
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
            if ($allow_expiry) {
                list($headerStr, $payloadStr, $signatureStr) = explode('.', $token);
                $payload = json_decode(base64_decode($payloadStr));
                $claims = MyTokenClaims::fromDecodedToken($payload);
                return $claims;
            }
        } catch (UnexpectedValueException $e) {
            log_error(__METHOD__ . ' - unexpected value exception ' . $e->getMessage());
        }

        return null;
    }

    public static function create_tokens($user_id, $generate_refresh_token = true)
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
            return null;
        }

        $now = new \DateTimeImmutable();
        $access_issued_at = $now;
        $access_expires_at = $access_issued_at->modify('+15 minutes');
        include CONFIG_PATH . 'app.php';
        $access_payload = [
            'iss' => APP_DOMAIN,
            'iat' => $access_issued_at->getTimestamp(),
            'exp' => $access_expires_at->getTimestamp(),
            'user_id' => $user_id
        ];
        $access_token = JWT::encode($access_payload, $private_key, self::JWT_ALGORITHM);

        $refresh_token = '';
        if ($generate_refresh_token) {
            $refresh_issued_at = $now;
            $refresh_expires_at = $refresh_issued_at->modify('+180 days');
            $refresh_payload = [
                'iss' => APP_DOMAIN,
                'iat' => $refresh_issued_at->getTimestamp(),
                'exp' => $refresh_expires_at->getTimestamp(),
                'user_id' => $user_id
            ];
            $refresh_token = JWT::encode($refresh_payload, $private_key, self::JWT_ALGORITHM);
        }

        return [$access_token, $refresh_token];
    }
}
