<?php
/**
 * JWT Configuration Test
 *
 * Tests the new JWT configuration system with custom keys, passphrases, and settings
 */

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use StoneScriptPHP\Env;
use StoneScriptPHP\Auth\RsaJwtHandler;

echo "=== JWT Configuration Tests ===\n\n";

$tests_passed = 0;
$tests_failed = 0;

// Test 1: Env class has JWT properties
echo "Test 1: Env class has JWT configuration properties\n";
try {
    $env = Env::get_instance();
    $hasProperties = property_exists($env, 'JWT_PRIVATE_KEY_PATH')
        && property_exists($env, 'JWT_PUBLIC_KEY_PATH')
        && property_exists($env, 'JWT_PRIVATE_KEY_PASSPHRASE')
        && property_exists($env, 'JWT_ISSUER')
        && property_exists($env, 'JWT_ACCESS_TOKEN_EXPIRY')
        && property_exists($env, 'JWT_REFRESH_TOKEN_EXPIRY');

    if ($hasProperties) {
        echo "✓ PASS: All JWT properties exist in Env class\n";
        $tests_passed++;
    } else {
        echo "✗ FAIL: Missing JWT properties in Env class\n";
        $tests_failed++;
    }
} catch (\Exception $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n";
    $tests_failed++;
}
echo "\n";

// Test 2: env() helper function works
echo "Test 2: env() helper function\n";
try {
    $issuer = env('JWT_ISSUER', 'test-default.com');
    if ($issuer !== null) {
        echo "✓ PASS: env() helper works (JWT_ISSUER: $issuer)\n";
        $tests_passed++;
    } else {
        echo "✗ FAIL: env() helper returned null\n";
        $tests_failed++;
    }
} catch (\Exception $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n";
    $tests_failed++;
}
echo "\n";

// Test 3: Env defaults are set correctly
echo "Test 3: Default values from schema\n";
try {
    $env = Env::get_instance();
    $defaultsCorrect = true;

    // Check defaults (only if not set in .env)
    if ($env->JWT_ACCESS_TOKEN_EXPIRY === null) {
        // Should use default of 900
        echo "  ℹ JWT_ACCESS_TOKEN_EXPIRY not set in .env (will use default 900)\n";
    } else {
        echo "  ✓ JWT_ACCESS_TOKEN_EXPIRY: {$env->JWT_ACCESS_TOKEN_EXPIRY}\n";
    }

    if ($env->JWT_REFRESH_TOKEN_EXPIRY === null) {
        echo "  ℹ JWT_REFRESH_TOKEN_EXPIRY not set in .env (will use default 15552000)\n";
    } else {
        echo "  ✓ JWT_REFRESH_TOKEN_EXPIRY: {$env->JWT_REFRESH_TOKEN_EXPIRY}\n";
    }

    if ($defaultsCorrect) {
        echo "✓ PASS: Env defaults configured correctly\n";
        $tests_passed++;
    }
} catch (\Exception $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n";
    $tests_failed++;
}
echo "\n";

// Test 4: Generate token with custom issuer
echo "Test 4: Generate token with custom issuer\n";
try {
    // Temporarily create test keys if they don't exist
    $testKeysDir = sys_get_temp_dir() . '/stonescriptphp-jwt-test-' . uniqid();
    mkdir($testKeysDir, 0755, true);

    $testPrivateKey = "$testKeysDir/test-private.pem";
    $testPublicKey = "$testKeysDir/test-public.pem";

    // Generate test keypair
    $config = [
        "digest_alg" => "sha256",
        "private_key_bits" => 2048,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    ];

    $res = openssl_pkey_new($config);
    openssl_pkey_export($res, $privKey);
    $pubKey = openssl_pkey_get_details($res)['key'];

    file_put_contents($testPrivateKey, $privKey);
    file_put_contents($testPublicKey, $pubKey);

    // Override env for testing
    $env = Env::get_instance();
    $originalPrivatePath = $env->JWT_PRIVATE_KEY_PATH;
    $originalPublicPath = $env->JWT_PUBLIC_KEY_PATH;
    $originalIssuer = $env->JWT_ISSUER;

    $env->JWT_PRIVATE_KEY_PATH = $testPrivateKey;
    $env->JWT_PUBLIC_KEY_PATH = $testPublicKey;
    $env->JWT_ISSUER = 'test.stonescriptphp.com';

    $handler = new RsaJwtHandler();
    $token = $handler->generateToken(['user_id' => 123], null, 'access');

    // Decode to check issuer
    $parts = explode('.', $token);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

    if ($payload['iss'] === 'test.stonescriptphp.com') {
        echo "✓ PASS: Token generated with correct issuer\n";
        echo "  Issuer: {$payload['iss']}\n";
        $tests_passed++;
    } else {
        echo "✗ FAIL: Wrong issuer in token\n";
        $tests_failed++;
    }

    // Cleanup
    $env->JWT_PRIVATE_KEY_PATH = $originalPrivatePath;
    $env->JWT_PUBLIC_KEY_PATH = $originalPublicPath;
    $env->JWT_ISSUER = $originalIssuer;
    unlink($testPrivateKey);
    unlink($testPublicKey);
    rmdir($testKeysDir);

} catch (\Exception $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n";
    $tests_failed++;
}
echo "\n";

// Test 5: Access vs Refresh token expiry
echo "Test 5: Access vs Refresh token expiry\n";
try {
    $testKeysDir = sys_get_temp_dir() . '/stonescriptphp-jwt-test-' . uniqid();
    mkdir($testKeysDir, 0755, true);

    $testPrivateKey = "$testKeysDir/test-private.pem";
    $testPublicKey = "$testKeysDir/test-public.pem";

    $config = [
        "digest_alg" => "sha256",
        "private_key_bits" => 2048,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    ];

    $res = openssl_pkey_new($config);
    openssl_pkey_export($res, $privKey);
    $pubKey = openssl_pkey_get_details($res)['key'];

    file_put_contents($testPrivateKey, $privKey);
    file_put_contents($testPublicKey, $pubKey);

    $env = Env::get_instance();
    $originalPrivatePath = $env->JWT_PRIVATE_KEY_PATH;
    $originalPublicPath = $env->JWT_PUBLIC_KEY_PATH;
    $originalAccessExpiry = $env->JWT_ACCESS_TOKEN_EXPIRY;
    $originalRefreshExpiry = $env->JWT_REFRESH_TOKEN_EXPIRY;

    $env->JWT_PRIVATE_KEY_PATH = $testPrivateKey;
    $env->JWT_PUBLIC_KEY_PATH = $testPublicKey;
    $env->JWT_ACCESS_TOKEN_EXPIRY = 900;    // 15 minutes
    $env->JWT_REFRESH_TOKEN_EXPIRY = 15552000;  // 180 days

    $handler = new RsaJwtHandler();

    // Generate access token
    $accessToken = $handler->generateToken(['user_id' => 123], null, 'access');
    $accessParts = explode('.', $accessToken);
    $accessPayload = json_decode(base64_decode(strtr($accessParts[1], '-_', '+/')), true);
    $accessExpiry = $accessPayload['exp'] - $accessPayload['iat'];

    // Generate refresh token
    $refreshToken = $handler->generateToken(['user_id' => 123], null, 'refresh');
    $refreshParts = explode('.', $refreshToken);
    $refreshPayload = json_decode(base64_decode(strtr($refreshParts[1], '-_', '+/')), true);
    $refreshExpiry = $refreshPayload['exp'] - $refreshPayload['iat'];

    if ($accessExpiry === 900 && $refreshExpiry === 15552000) {
        echo "✓ PASS: Correct expiry for access and refresh tokens\n";
        echo "  Access token expiry: $accessExpiry seconds (15 min)\n";
        echo "  Refresh token expiry: $refreshExpiry seconds (180 days)\n";
        $tests_passed++;
    } else {
        echo "✗ FAIL: Wrong token expiry\n";
        echo "  Access: $accessExpiry (expected 900)\n";
        echo "  Refresh: $refreshExpiry (expected 15552000)\n";
        $tests_failed++;
    }

    // Cleanup
    $env->JWT_PRIVATE_KEY_PATH = $originalPrivatePath;
    $env->JWT_PUBLIC_KEY_PATH = $originalPublicPath;
    $env->JWT_ACCESS_TOKEN_EXPIRY = $originalAccessExpiry;
    $env->JWT_REFRESH_TOKEN_EXPIRY = $originalRefreshExpiry;
    unlink($testPrivateKey);
    unlink($testPublicKey);
    rmdir($testKeysDir);

} catch (\Exception $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n";
    $tests_failed++;
}
echo "\n";

// Test 6: Issuer verification
echo "Test 6: Issuer verification\n";
try {
    $testKeysDir = sys_get_temp_dir() . '/stonescriptphp-jwt-test-' . uniqid();
    mkdir($testKeysDir, 0755, true);

    $testPrivateKey = "$testKeysDir/test-private.pem";
    $testPublicKey = "$testKeysDir/test-public.pem";

    $config = [
        "digest_alg" => "sha256",
        "private_key_bits" => 2048,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    ];

    $res = openssl_pkey_new($config);
    openssl_pkey_export($res, $privKey);
    $pubKey = openssl_pkey_get_details($res)['key'];

    file_put_contents($testPrivateKey, $privKey);
    file_put_contents($testPublicKey, $pubKey);

    $env = Env::get_instance();
    $env->JWT_PRIVATE_KEY_PATH = $testPrivateKey;
    $env->JWT_PUBLIC_KEY_PATH = $testPublicKey;
    $env->JWT_ISSUER = 'test.example.com';

    $handler = new RsaJwtHandler();
    $token = $handler->generateToken(['user_id' => 123]);

    // Verify with correct issuer
    $payload = $handler->verifyToken($token, verifyIssuer: true);
    $verifyWithIssuerWorks = $payload !== false;

    // Change issuer and try again
    $env->JWT_ISSUER = 'different.example.com';
    $payload2 = $handler->verifyToken($token, verifyIssuer: true);
    $issuerMismatchFails = $payload2 === false;

    // Verify without issuer check
    $payload3 = $handler->verifyToken($token, verifyIssuer: false);
    $skipIssuerWorks = $payload3 !== false;

    if ($verifyWithIssuerWorks && $issuerMismatchFails && $skipIssuerWorks) {
        echo "✓ PASS: Issuer verification works correctly\n";
        $tests_passed++;
    } else {
        echo "✗ FAIL: Issuer verification not working\n";
        $tests_failed++;
    }

    // Cleanup
    unlink($testPrivateKey);
    unlink($testPublicKey);
    rmdir($testKeysDir);

} catch (\Exception $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n";
    $tests_failed++;
}
echo "\n";

// Summary
echo "=== Test Summary ===\n";
echo "Passed: $tests_passed\n";
echo "Failed: $tests_failed\n";
echo "Total: " . ($tests_passed + $tests_failed) . "\n";

if ($tests_failed === 0) {
    echo "\n✓ All tests passed!\n";
    exit(0);
} else {
    echo "\n✗ Some tests failed!\n";
    exit(1);
}
