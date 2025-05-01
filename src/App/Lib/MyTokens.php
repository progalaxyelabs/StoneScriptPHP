<?php

namespace App\Lib;

use App\Models\MyTokenClaims;
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;
use \Firebase\JWT\SignatureInvalidException;
use \Firebase\JWT\BeforeValidException;
use \Firebase\JWT\ExpiredException;
use DomainException;
use InvalidArgumentException;
use UnexpectedValueException;

class MyTokens
{

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

    protected function tokenFromAuthorizationHeader($request)
    {
        $authorization_header = $request->getServer('HTTP_AUTHORIZATION');
        if (!$authorization_header) {
            log_debug('identifyUserSignedIn - no http_authorization header');
            return '';
        }

        $token = substr($authorization_header, 7); // remove 'Bearer ' prefix
        return $token;
    }

    public function decodeToken($token, $allow_expiry = false)
    {
        $public_key = file_get_contents(ROOT_PATH . 'instituteappapikey.pub');
        if (!$public_key) {
            log_error(__METHOD__ . ' - no public key file');
            return null;
        }

        try {
            $decoded_token = JWT::decode($token, new Key($public_key, 'RS256'));
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
        
        $pem_file_path = ROOT_PATH . 'key.pem';
        if(!file_exists($pem_file_path)) {
            log_error('user signin - no private key file at ' . $pem_file_path);
            return null;
        }

        $pass_phrase = '12345678';
        $private_key = openssl_pkey_get_private(
            // 'file://' . $pem_file_path,
            file_get_contents($pem_file_path),
            $pass_phrase
        );
        if (!$private_key) {
            log_error('user signin - unable to read the private key file using the passphrase');
            return null;
        }

        $now = new \DateTimeImmutable();
        $access_issued_at = $now;
        $access_expires_at = $access_issued_at->modify('+15 minutes');
        $access_payload = [
            'iss' => 'instituteapp.in',
            'iat' => $access_issued_at->getTimestamp(),
            'exp' => $access_expires_at->getTimestamp(),
            'user_id' => $user_id
        ];
        $access_token = JWT::encode($access_payload, $private_key, 'RS256');

        $refresh_token = '';
        if ($generate_refresh_token) {
            $refresh_issued_at = $now;
            $refresh_expires_at = $refresh_issued_at->modify('+180 days');
            $refresh_payload = [
                'iss' => 'instituteapp.in',
                'iat' => $refresh_issued_at->getTimestamp(),
                'exp' => $refresh_expires_at->getTimestamp(),
                'user_id' => $user_id
            ];
            $refresh_token = JWT::encode($refresh_payload, $private_key, 'RS256');
        }

        return [$access_token, $refresh_token];
    }
}
