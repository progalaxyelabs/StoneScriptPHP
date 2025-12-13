<?php

/**
 * Simple Logging Security Test
 *
 * Tests that the Logger properly sanitizes sensitive data
 */

// Define DEBUG_MODE constant before loading anything
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', true);
}

require_once __DIR__ . '/../../vendor/autoload.php';

use StoneScriptPHP\Logger;

echo "\033[1;33m========================================\033[0m\n";
echo "\033[1;33mLogging Security Test\033[0m\n";
echo "\033[1;33m========================================\033[0m\n\n";

// Get logger instance
$logger = Logger::get_instance();
$logger->configure(console: false, file: false, json: false);

$testsPassed = 0;
$testsFailed = 0;

// Test 1: Sensitive data should be redacted
echo "\033[0;36mTest 1: Sensitive data redaction...\033[0m\n";

$logger->log_info("User login attempt", [
    'email' => 'test@example.com',
    'password' => 'SuperSecret123!',
    'token' => 'abc123xyz789',
    'api_key' => 'sk_live_123456789',
    'user_agent' => 'Mozilla/5.0'
]);

$logs = $logger->get_all();

if (empty($logs)) {
    echo "\033[0;31m  ✗ No logs captured! DEBUG_MODE may not be enabled.\033[0m\n";
    $testsFailed++;
} else {
    $lastLog = end($logs);

    // Check that sensitive fields are redacted
    $sensitiveRedacted = (
        isset($lastLog['context']['password']) &&
        isset($lastLog['context']['token']) &&
        isset($lastLog['context']['api_key']) &&
        $lastLog['context']['password'] === '***REDACTED***' &&
        $lastLog['context']['token'] === '***REDACTED***' &&
        $lastLog['context']['api_key'] === '***REDACTED***'
    );

    // Check that non-sensitive fields are preserved
    $nonSensitivePreserved = (
        isset($lastLog['context']['email']) &&
        isset($lastLog['context']['user_agent']) &&
        $lastLog['context']['email'] === 'test@example.com' &&
        $lastLog['context']['user_agent'] === 'Mozilla/5.0'
    );

    if ($sensitiveRedacted && $nonSensitivePreserved) {
        echo "\033[0;32m  ✓ Sensitive data properly redacted\033[0m\n";
        echo "    - password: " . $lastLog['context']['password'] . "\n";
        echo "    - token: " . $lastLog['context']['token'] . "\n";
        echo "    - api_key: " . $lastLog['context']['api_key'] . "\n";
        echo "    - email: " . $lastLog['context']['email'] . " (preserved)\n";
        $testsPassed++;
    } else {
        echo "\033[0;31m  ✗ Sensitive data redaction failed\033[0m\n";
        echo "    Context: " . json_encode($lastLog['context']) . "\n";
        $testsFailed++;
    }
}

// Test 2: Nested sensitive data should be redacted
echo "\n\033[0;36mTest 2: Nested sensitive data redaction...\033[0m\n";

$logger->log_info("User profile update", [
    'user_id' => 123,
    'changes' => [
        'display_name' => 'John Doe',
        'password_hash' => 'bcrypt_hash_here',
        'session' => 'session_id_here'
    ],
    'metadata' => [
        'ip' => '192.168.1.1',
        'authorization' => 'Bearer token_here'
    ]
]);

$logs = $logger->get_all();
$lastLog = end($logs);

$nestedSensitiveRedacted = (
    $lastLog['context']['changes']['password_hash'] === '***REDACTED***' &&
    $lastLog['context']['changes']['session'] === '***REDACTED***' &&
    $lastLog['context']['metadata']['authorization'] === '***REDACTED***'
);

$nestedNonSensitivePreserved = (
    $lastLog['context']['user_id'] === 123 &&
    $lastLog['context']['changes']['display_name'] === 'John Doe' &&
    $lastLog['context']['metadata']['ip'] === '192.168.1.1'
);

if ($nestedSensitiveRedacted && $nestedNonSensitivePreserved) {
    echo "\033[0;32m  ✓ Nested sensitive data properly redacted\033[0m\n";
    echo "    - changes.password_hash: " . $lastLog['context']['changes']['password_hash'] . "\n";
    echo "    - changes.session: " . $lastLog['context']['changes']['session'] . "\n";
    echo "    - metadata.authorization: " . $lastLog['context']['metadata']['authorization'] . "\n";
    echo "    - user_id: " . $lastLog['context']['user_id'] . " (preserved)\n";
    $testsPassed++;
} else {
    echo "\033[0;31m  ✗ Nested sensitive data redaction failed\033[0m\n";
    echo "    Context: " . json_encode($lastLog['context']) . "\n";
    $testsFailed++;
}

// Test 3: Log levels work correctly
echo "\n\033[0;36mTest 3: Log levels...\033[0m\n";

$logger->log_debug("Debug message");
$logger->log_info("Info message");
$logger->log_warning("Warning message");
$logger->log_error("Error message");
$logger->log_critical("Critical message");

$logs = $logger->get_all();
$lastFiveLogs = array_slice($logs, -5);

$levelCount = count(array_unique(array_column($lastFiveLogs, 'level')));

if ($levelCount === 5) {
    echo "\033[0;32m  ✓ All log levels working\033[0m\n";
    foreach ($lastFiveLogs as $log) {
        echo "    - " . str_pad($log['level'], 10) . ": " . $log['message'] . "\n";
    }
    $testsPassed++;
} else {
    echo "\033[0;31m  ✗ Log levels not working correctly\033[0m\n";
    $testsFailed++;
}

// Test 4: Log structure contains required fields
echo "\n\033[0;36mTest 4: Log entry structure...\033[0m\n";

$logs = $logger->get_all();
$sampleLog = $logs[0];

$hasRequiredFields = (
    isset($sampleLog['timestamp']) &&
    isset($sampleLog['level']) &&
    isset($sampleLog['message']) &&
    isset($sampleLog['context']) &&
    isset($sampleLog['memory']) &&
    isset($sampleLog['pid'])
);

if ($hasRequiredFields) {
    echo "\033[0;32m  ✓ Log entries have correct structure\033[0m\n";
    echo "    Fields: " . implode(', ', array_keys($sampleLog)) . "\n";
    echo "    - timestamp: " . $sampleLog['timestamp'] . "\n";
    echo "    - level: " . $sampleLog['level'] . "\n";
    echo "    - message: " . $sampleLog['message'] . "\n";
    echo "    - memory: " . number_format($sampleLog['memory'] / 1024 / 1024, 2) . " MB\n";
    echo "    - pid: " . $sampleLog['pid'] . "\n";
    $testsPassed++;
} else {
    echo "\033[0;31m  ✗ Log entries missing required fields\033[0m\n";
    echo "    Fields: " . implode(', ', array_keys($sampleLog)) . "\n";
    $testsFailed++;
}

// Test 5: Case-insensitive pattern matching for sensitive fields
echo "\n\033[0;36mTest 5: Case-insensitive sensitive field detection...\033[0m\n";

$logger->log_info("Mixed case sensitive fields", [
    'Password' => 'secret1',
    'API_Key' => 'secret2',
    'Access_Token' => 'secret3',
    'USERNAME' => 'john_doe'  // Not sensitive
]);

$logs = $logger->get_all();
$lastLog = end($logs);

$caseInsensitiveWorking = (
    $lastLog['context']['Password'] === '***REDACTED***' &&
    $lastLog['context']['API_Key'] === '***REDACTED***' &&
    $lastLog['context']['Access_Token'] === '***REDACTED***' &&
    $lastLog['context']['USERNAME'] === 'john_doe'
);

if ($caseInsensitiveWorking) {
    echo "\033[0;32m  ✓ Case-insensitive detection working\033[0m\n";
    echo "    - Password: " . $lastLog['context']['Password'] . "\n";
    echo "    - API_Key: " . $lastLog['context']['API_Key'] . "\n";
    echo "    - Access_Token: " . $lastLog['context']['Access_Token'] . "\n";
    echo "    - USERNAME: " . $lastLog['context']['USERNAME'] . " (preserved)\n";
    $testsPassed++;
} else {
    echo "\033[0;31m  ✗ Case-insensitive detection failed\033[0m\n";
    echo "    Context: " . json_encode($lastLog['context']) . "\n";
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
    echo "\033[0;32m✅ All logging security tests passed!\033[0m\n\n";
    echo "Verified:\n";
    echo "  ✅ Sensitive data (passwords, tokens, secrets) is redacted\n";
    echo "  ✅ Non-sensitive data is preserved\n";
    echo "  ✅ Nested sensitive data is handled correctly\n";
    echo "  ✅ All log levels work properly\n";
    echo "  ✅ Log entries have proper structure\n";
    echo "  ✅ Case-insensitive pattern matching works\n\n";
    exit(0);
} else {
    echo "\033[0;31m❌ Some tests failed!\033[0m\n\n";
    exit(1);
}
