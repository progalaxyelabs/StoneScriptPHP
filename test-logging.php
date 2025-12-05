<?php
/**
 * Test script for logging and exception handling
 * Run with: php test-logging.php
 */

// Simulate bootstrap environment
define('INDEX_START_TIME', microtime(true));
define('ROOT_PATH', __DIR__ . DIRECTORY_SEPARATOR);
define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);
define('APP_PATH', SRC_PATH . 'App' . DIRECTORY_SEPARATOR);
define('CONFIG_PATH', SRC_PATH . 'config' . DIRECTORY_SEPARATOR);
define('FRAMEWORK_PATH', ROOT_PATH . 'Framework' . DIRECTORY_SEPARATOR);
define('DEBUG_MODE', true); // Enable debug mode for testing

// Load framework classes
require_once 'Framework/Logger.php';
require_once 'Framework/Exceptions.php';
require_once 'Framework/ExceptionHandler.php';
require_once 'Framework/functions.php';

use Framework\Logger;
use Framework\ExceptionHandler;

echo "===========================================\n";
echo "StoneScriptPHP Logging & Exception Test\n";
echo "===========================================\n\n";

// Register exception handler
ExceptionHandler::getInstance()->register();

echo "Testing Log Levels:\n";
echo "-------------------------------------------\n";

// Test all log levels
log_debug('This is a DEBUG message', ['user_id' => 123]);
log_info('This is an INFO message', ['action' => 'login']);
log_notice('This is a NOTICE message', ['event' => 'file_uploaded']);
log_warning('This is a WARNING message', ['memory_usage' => '85%']);
log_error('This is an ERROR message', ['error_code' => 'DB_CONNECTION_FAILED']);
log_critical('This is a CRITICAL message', ['service' => 'payment_gateway']);
log_alert('This is an ALERT message', ['disk_space' => '5%']);
log_emergency('This is an EMERGENCY message', ['status' => 'system_crash']);

echo "\n\nTesting HTTP Request Logging:\n";
echo "-------------------------------------------\n";

log_request('GET', '/api/users', 200, 45.23);
log_request('POST', '/api/orders', 201, 123.45);
log_request('GET', '/api/missing', 404, 12.34);
log_request('POST', '/api/error', 500, 567.89);

echo "\n\nTesting Custom Exceptions:\n";
echo "-------------------------------------------\n";

use Framework\{
    BadRequestException,
    UnauthorizedException,
    ValidationException,
    DatabaseException
};

// Test different exception types
try {
    echo "1. Testing BadRequestException...\n";
    throw new BadRequestException('Missing required field', ['field' => 'email']);
} catch (BadRequestException $e) {
    echo "   ✓ Caught: {$e->getMessage()} (HTTP {$e->getHttpStatusCode()})\n";
}

try {
    echo "2. Testing UnauthorizedException...\n";
    throw new UnauthorizedException('Invalid token');
} catch (UnauthorizedException $e) {
    echo "   ✓ Caught: {$e->getMessage()} (HTTP {$e->getHttpStatusCode()})\n";
}

try {
    echo "3. Testing ValidationException...\n";
    throw new ValidationException([
        'email' => 'Invalid email format',
        'password' => 'Password too short'
    ]);
} catch (ValidationException $e) {
    echo "   ✓ Caught: {$e->getMessage()} (HTTP {$e->getHttpStatusCode()})\n";
    echo "   Validation errors: " . json_encode($e->getValidationErrors()) . "\n";
}

try {
    echo "4. Testing DatabaseException...\n";
    throw new DatabaseException('Connection timeout', [
        'host' => 'localhost',
        'port' => 5432,
        'timeout' => 30
    ]);
} catch (DatabaseException $e) {
    echo "   ✓ Caught: {$e->getMessage()} (HTTP {$e->getHttpStatusCode()})\n";
    echo "   Context: " . json_encode($e->getContext()) . "\n";
}

echo "\n\nLog Output Configuration:\n";
echo "-------------------------------------------\n";
echo "Console: ✓ Enabled (with colors)\n";
echo "File:    ✓ Enabled (logs/" . date('Y-m-d') . ".log)\n";
echo "JSON:    ✗ Disabled (enable with Logger::getInstance()->configure())\n";

echo "\n\nTest Complete!\n";
echo "-------------------------------------------\n";
echo "Check logs/" . date('Y-m-d') . ".log for file output\n";
echo "All exceptions were caught and handled properly\n";
echo "===========================================\n";
