<?php

/**
 * CSRF Protection Test
 *
 * Demonstrates CSRF token generation and validation
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use StoneScriptPHP\Security\CsrfTokenHandler;

echo "\033[1;33m========================================\033[0m\n";
echo "\033[1;33mCSRF Protection Test\033[0m\n";
echo "\033[1;33m========================================\033[0m\n\n";

$handler = new CsrfTokenHandler();

$testsPassed = 0;
$testsFailed = 0;

// Test 1: Token generation
echo "\033[0;36mTest 1: Token generation\033[0m\n";
try {
    $token = $handler->generateToken(['action' => 'register']);

    if (!empty($token) && str_contains($token, '.')) {
        echo "\033[0;32m  ✓ Token generated successfully\033[0m\n";
        echo "    Token: " . substr($token, 0, 50) . "...\n";
        $testsPassed++;
    } else {
        echo "\033[0;31m  ✗ Invalid token format\033[0m\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "\033[0;31m  ✗ Token generation failed: {$e->getMessage()}\033[0m\n";
    $testsFailed++;
}

// Test 2: Token validation (valid token)
echo "\n\033[0;36mTest 2: Valid token validation\033[0m\n";
$token = $handler->generateToken(['action' => 'login']);
$valid = $handler->validateToken($token, ['action' => 'login']);

if ($valid) {
    echo "\033[0;32m  ✓ Valid token accepted\033[0m\n";
    $testsPassed++;
} else {
    echo "\033[0;31m  ✗ Valid token rejected\033[0m\n";
    $testsFailed++;
}

// Test 3: Token validation (single-use)
echo "\n\033[0;36mTest 3: Single-use token enforcement\033[0m\n";
$token = $handler->generateToken(['action' => 'test']);
$handler->validateToken($token, ['action' => 'test']); // First use
$valid = $handler->validateToken($token, ['action' => 'test']); // Second use

if (!$valid) {
    echo "\033[0;32m  ✓ Token correctly rejected on reuse\033[0m\n";
    $testsPassed++;
} else {
    echo "\033[0;31m  ✗ Token accepted multiple times (should be single-use)\033[0m\n";
    $testsFailed++;
}

// Test 4: Token validation (wrong action)
echo "\n\033[0;36mTest 4: Action mismatch detection\033[0m\n";
$token = $handler->generateToken(['action' => 'register']);
$valid = $handler->validateToken($token, ['action' => 'login']);

if (!$valid) {
    echo "\033[0;32m  ✓ Token correctly rejected for wrong action\033[0m\n";
    $testsPassed++;
} else {
    echo "\033[0;31m  ✗ Token accepted for wrong action\033[0m\n";
    $testsFailed++;
}

// Test 5: Token validation (empty token)
echo "\n\033[0;36mTest 5: Empty token rejection\033[0m\n";
$valid = $handler->validateToken('', ['action' => 'test']);

if (!$valid) {
    echo "\033[0;32m  ✓ Empty token correctly rejected\033[0m\n";
    $testsPassed++;
} else {
    echo "\033[0;31m  ✗ Empty token accepted\033[0m\n";
    $testsFailed++;
}

// Test 6: Token validation (malformed token)
echo "\n\033[0;36mTest 6: Malformed token rejection\033[0m\n";
$valid = $handler->validateToken('invalid-token-here', ['action' => 'test']);

if (!$valid) {
    echo "\033[0;32m  ✓ Malformed token correctly rejected\033[0m\n";
    $testsPassed++;
} else {
    echo "\033[0;31m  ✗ Malformed token accepted\033[0m\n";
    $testsFailed++;
}

// Test 7: Token validation (tampered signature)
echo "\n\033[0;36mTest 7: Tampered signature detection\033[0m\n";
$token = $handler->generateToken(['action' => 'test']);
$tamperedToken = $token . 'x'; // Tamper with signature
$valid = $handler->validateToken($tamperedToken, ['action' => 'test']);

if (!$valid) {
    echo "\033[0;32m  ✓ Tampered token correctly rejected\033[0m\n";
    $testsPassed++;
} else {
    echo "\033[0;31m  ✗ Tampered token accepted\033[0m\n";
    $testsFailed++;
}

// Test 8: Multiple tokens generation
echo "\n\033[0;36mTest 8: Multiple token generation\033[0m\n";
try {
    $tokens = [];
    for ($i = 0; $i < 5; $i++) {
        $tokens[] = $handler->generateToken(['action' => 'test']);
    }

    // Validate all tokens
    $allValid = true;
    foreach ($tokens as $token) {
        if (!$handler->validateToken($token, ['action' => 'test'])) {
            $allValid = false;
            break;
        }
    }

    if ($allValid) {
        echo "\033[0;32m  ✓ Multiple tokens work independently\033[0m\n";
        $testsPassed++;
    } else {
        echo "\033[0;31m  ✗ Multiple tokens validation failed\033[0m\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "\033[0;31m  ✗ Multiple token generation failed: {$e->getMessage()}\033[0m\n";
    $testsFailed++;
}

// Summary
echo "\n\033[1;33m========================================\033[0m\n";
echo "\033[1;33mTest Summary\033[0m\n";
echo "\033[1;33m========================================\033[0m\n\n";

echo "Total Tests: " . ($testsPassed + $testsFailed) . "\n";
echo "\033[0;32mPassed: $testsPassed\033[0m\n";
echo "\033[0;31mFailed: $testsFailed\033[0m\n\n";

if ($testsFailed === 0) {
    echo "\033[0;32m✅ All CSRF protection tests passed!\033[0m\n\n";
    echo "Verified:\n";
    echo "  ✅ Token generation works\n";
    echo "  ✅ Valid tokens are accepted\n";
    echo "  ✅ Tokens are single-use only\n";
    echo "  ✅ Action mismatch detected\n";
    echo "  ✅ Empty tokens rejected\n";
    echo "  ✅ Malformed tokens rejected\n";
    echo "  ✅ Tampered signatures detected\n";
    echo "  ✅ Multiple tokens work independently\n\n";
    echo "CSRF protection is ready for production!\n\n";
    exit(0);
} else {
    echo "\033[0;31m❌ Some tests failed!\033[0m\n\n";
    exit(1);
}
