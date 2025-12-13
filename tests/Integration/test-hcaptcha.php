<?php
/**
 * Test hCaptcha Integration
 *
 * This test verifies:
 * 1. HCaptchaVerifier auto-disables when keys not configured
 * 2. Middleware passes through when disabled
 * 3. Middleware blocks when enabled but no token provided
 * 4. Token extraction from multiple sources
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use StoneScriptPHP\Security\HCaptchaVerifier;
use StoneScriptPHP\Routing\Middleware\HCaptchaMiddleware;
use StoneScriptPHP\ApiResponse;

echo "=== hCaptcha Integration Tests ===\n\n";

$tests_passed = 0;
$tests_failed = 0;

// Test 1: Auto-disable when keys not configured
echo "Test 1: Auto-disable when not configured\n";
putenv('HCAPTCHA_SITE_KEY=');
putenv('HCAPTCHA_SECRET_KEY=');
$verifier = new HCaptchaVerifier();
if (!$verifier->isEnabled()) {
    echo "✓ PASS: hCaptcha correctly disabled when keys not set\n";
    $tests_passed++;
} else {
    echo "✗ FAIL: hCaptcha should be disabled\n";
    $tests_failed++;
}
echo "\n";

// Test 2: Verify returns true when disabled
echo "Test 2: Verification passes when disabled\n";
if ($verifier->verify('fake-token')) {
    echo "✓ PASS: Verification passes (fail-open) when disabled\n";
    $tests_passed++;
} else {
    echo "✗ FAIL: Should pass when disabled\n";
    $tests_failed++;
}
echo "\n";

// Test 3: Enable with test keys
echo "Test 3: Enable with test keys\n";
$testVerifier = new HCaptchaVerifier(
    '0x0000000000000000000000000000000000000000', // Test secret key
    '10000000-ffff-ffff-ffff-000000000001' // Test site key
);
if ($testVerifier->isEnabled()) {
    echo "✓ PASS: hCaptcha enabled with test keys\n";
    $tests_passed++;
} else {
    echo "✗ FAIL: Should be enabled with test keys\n";
    $tests_failed++;
}
echo "\n";

// Test 4: Middleware auto-disables
echo "Test 4: Middleware auto-disables when not configured\n";
$middleware = new HCaptchaMiddleware(['/test/route']);
$request = [
    'method' => 'POST',
    'path' => '/test/route',
    'body' => []
];
$nextCalled = false;
$next = function($req) use (&$nextCalled) {
    $nextCalled = true;
    return new ApiResponse('success', 'Test passed');
};

$result = $middleware->handle($request, $next);
if ($nextCalled && $result->status === 'success') {
    echo "✓ PASS: Middleware allows request when disabled\n";
    $tests_passed++;
} else {
    echo "✗ FAIL: Middleware should allow request when disabled\n";
    $tests_failed++;
}
echo "\n";

// Test 5: Middleware blocks when enabled but no token
echo "Test 5: Middleware blocks when enabled without token\n";
putenv('HCAPTCHA_SECRET_KEY=0x0000000000000000000000000000000000000000');
putenv('HCAPTCHA_SITE_KEY=10000000-ffff-ffff-ffff-000000000001');
$middleware = new HCaptchaMiddleware(['/test/route']);
$nextCalled = false;
$result = $middleware->handle($request, $next);
if (!$nextCalled && $result->status === 'error' && $result->data['error_code'] === 'CAPTCHA_REQUIRED') {
    echo "✓ PASS: Middleware blocks request without token\n";
    $tests_passed++;
} else {
    echo "✗ FAIL: Middleware should block request without token\n";
    $tests_failed++;
}
echo "\n";

// Test 6: Token extraction from body
echo "Test 6: Token extraction from h-captcha-response field\n";
$requestWithToken = [
    'method' => 'POST',
    'path' => '/test/route',
    'body' => [
        'h-captcha-response' => 'test-token-123'
    ]
];
// Use reflection to test private method
$reflection = new ReflectionClass($middleware);
$method = $reflection->getMethod('extractToken');
$method->setAccessible(true);
$token = $method->invoke($middleware, $requestWithToken);
if ($token === 'test-token-123') {
    echo "✓ PASS: Token extracted from h-captcha-response\n";
    $tests_passed++;
} else {
    echo "✗ FAIL: Token not extracted correctly\n";
    $tests_failed++;
}
echo "\n";

// Test 7: Token extraction from header
echo "Test 7: Token extraction from X-HCaptcha-Token header\n";
$requestWithHeader = [
    'method' => 'POST',
    'path' => '/test/route',
    'body' => [],
    'headers' => [
        'X-HCaptcha-Token' => 'header-token-456'
    ]
];
$token = $method->invoke($middleware, $requestWithHeader);
if ($token === 'header-token-456') {
    echo "✓ PASS: Token extracted from header\n";
    $tests_passed++;
} else {
    echo "✗ FAIL: Token not extracted from header\n";
    $tests_failed++;
}
echo "\n";

// Test 8: GET requests not protected
echo "Test 8: GET requests not protected\n";
$getRequest = [
    'method' => 'GET',
    'path' => '/test/route'
];
$nextCalled = false;
$result = $middleware->handle($getRequest, $next);
if ($nextCalled) {
    echo "✓ PASS: GET requests allowed without CAPTCHA\n";
    $tests_passed++;
} else {
    echo "✗ FAIL: GET requests should be allowed\n";
    $tests_failed++;
}
echo "\n";

// Test 9: Unprotected routes allowed
echo "Test 9: Unprotected routes allowed\n";
$unprotectedRequest = [
    'method' => 'POST',
    'path' => '/api/public/endpoint',
    'body' => []
];
$nextCalled = false;
$result = $middleware->handle($unprotectedRequest, $next);
if ($nextCalled) {
    echo "✓ PASS: Unprotected routes allowed\n";
    $tests_passed++;
} else {
    echo "✗ FAIL: Unprotected routes should be allowed\n";
    $tests_failed++;
}
echo "\n";

// Test 10: Frontend config
echo "Test 10: Frontend configuration\n";
$config = $testVerifier->getFrontendConfig();
if ($config['enabled'] === true && $config['site_key'] === '10000000-ffff-ffff-ffff-000000000001') {
    echo "✓ PASS: Frontend config correct\n";
    $tests_passed++;
} else {
    echo "✗ FAIL: Frontend config incorrect\n";
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
