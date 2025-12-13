<?php

/**
 * Middleware System Test Suite
 *
 * Basic test suite to verify middleware functionality
 */

// Setup paths
define('ROOT_PATH', realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR);
define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);
define('DEBUG_MODE', true);

// Simple autoloader
spl_autoload_register(function ($class) {
    $path = SRC_PATH . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

// Simple logging function for tests
function log_debug($message) {
    // Suppress logs during tests
}

use StoneScriptPHP\Routing\MiddlewarePipeline;
use StoneScriptPHP\Routing\MiddlewareInterface;
use StoneScriptPHP\Routing\Middleware\LoggingMiddleware;
use StoneScriptPHP\Routing\Middleware\AuthMiddleware;
use StoneScriptPHP\ApiResponse;

// Test counter
$testsPassed = 0;
$testsFailed = 0;

function test($name, $callback) {
    global $testsPassed, $testsFailed;

    echo "\n🧪 Test: $name\n";

    try {
        $result = $callback();
        if ($result === true) {
            echo "   ✅ PASSED\n";
            $testsPassed++;
        } else {
            echo "   ❌ FAILED: $result\n";
            $testsFailed++;
        }
    } catch (Exception $e) {
        echo "   ❌ EXCEPTION: " . $e->getMessage() . "\n";
        $testsFailed++;
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "StoneScriptPHP Middleware Test Suite\n";
echo str_repeat("=", 60) . "\n";

// Test 1: Middleware Pipeline Creation
test("MiddlewarePipeline can be created", function() {
    $pipeline = new MiddlewarePipeline();
    return $pipeline instanceof MiddlewarePipeline;
});

// Test 2: Add middleware to pipeline
test("Middleware can be added to pipeline", function() {
    $pipeline = new MiddlewarePipeline();

    $middleware = new class implements MiddlewareInterface {
        public function handle(array $request, callable $next): ?ApiResponse {
            return $next($request);
        }
    };

    $pipeline->pipe($middleware);
    return $pipeline->count() === 1;
});

// Test 3: Middleware executes in order
test("Middleware executes in order", function() {
    $pipeline = new MiddlewarePipeline();
    $order = [];

    $middleware1 = new class($order) implements MiddlewareInterface {
        private $order;
        public function __construct(&$order) {
            $this->order = &$order;
        }
        public function handle(array $request, callable $next): ?ApiResponse {
            $this->order[] = 1;
            return $next($request);
        }
    };

    $middleware2 = new class($order) implements MiddlewareInterface {
        private $order;
        public function __construct(&$order) {
            $this->order = &$order;
        }
        public function handle(array $request, callable $next): ?ApiResponse {
            $this->order[] = 2;
            return $next($request);
        }
    };

    $pipeline->pipe($middleware1)->pipe($middleware2);

    $response = $pipeline->process([], function($request) {
        return new ApiResponse('ok', 'Success');
    });

    return $order === [1, 2] && $response->status === 'ok';
});

// Test 4: Middleware can short-circuit
test("Middleware can short-circuit the pipeline", function() {
    $pipeline = new MiddlewarePipeline();
    $executed = false;

    $blockingMiddleware = new class implements MiddlewareInterface {
        public function handle(array $request, callable $next): ?ApiResponse {
            return new ApiResponse('error', 'Blocked');
        }
    };

    $pipeline->pipe($blockingMiddleware);

    $response = $pipeline->process([], function($request) use (&$executed) {
        $executed = true;
        return new ApiResponse('ok', 'Success');
    });

    return $response->status === 'error' && $executed === false;
});

// Test 5: Middleware can modify request
test("Middleware can modify request data", function() {
    $pipeline = new MiddlewarePipeline();

    $modifyingMiddleware = new class implements MiddlewareInterface {
        public function handle(array $request, callable $next): ?ApiResponse {
            $request['modified'] = true;
            return $next($request);
        }
    };

    $pipeline->pipe($modifyingMiddleware);

    $finalRequest = null;
    $response = $pipeline->process([], function($request) use (&$finalRequest) {
        $finalRequest = $request;
        return new ApiResponse('ok', 'Success');
    });

    return isset($finalRequest['modified']) && $finalRequest['modified'] === true;
});

// Test 6: AuthMiddleware blocks without token
test("AuthMiddleware blocks requests without token", function() {
    $_SERVER['REQUEST_URI'] = '/protected';

    $authMiddleware = new AuthMiddleware(
        validator: fn($token) => $token === 'valid-token'
    );

    $request = [];
    $called = false;

    $response = $authMiddleware->handle($request, function($req) use (&$called) {
        $called = true;
        return new ApiResponse('ok', 'Authorized');
    });

    return $response->status === 'error' &&
           $called === false &&
           strpos($response->message, 'Unauthorized') !== false;
});

// Test 7: AuthMiddleware allows excluded paths
test("AuthMiddleware allows excluded paths", function() {
    $_SERVER['REQUEST_URI'] = '/public';

    $authMiddleware = new AuthMiddleware(
        validator: fn($token) => $token === 'valid-token',
        excludedPaths: ['/public']
    );

    $request = [];
    $called = false;

    $response = $authMiddleware->handle($request, function($req) use (&$called) {
        $called = true;
        return new ApiResponse('ok', 'Success');
    });

    return $called === true && $response->status === 'ok';
});

// Test 8: Multiple middleware can be added at once
test("Multiple middleware can be added with pipes()", function() {
    $pipeline = new MiddlewarePipeline();

    $middleware1 = new class implements MiddlewareInterface {
        public function handle(array $request, callable $next): ?ApiResponse {
            return $next($request);
        }
    };

    $middleware2 = new class implements MiddlewareInterface {
        public function handle(array $request, callable $next): ?ApiResponse {
            return $next($request);
        }
    };

    $pipeline->pipes([$middleware1, $middleware2]);

    return $pipeline->count() === 2;
});

// Test 9: Pipeline with no middleware executes final handler
test("Pipeline with no middleware executes final handler", function() {
    $pipeline = new MiddlewarePipeline();
    $executed = false;

    $response = $pipeline->process([], function($request) use (&$executed) {
        $executed = true;
        return new ApiResponse('ok', 'Success');
    });

    return $executed === true && $response->status === 'ok';
});

// Test 10: LoggingMiddleware doesn't block requests
test("LoggingMiddleware passes requests through", function() {
    $loggingMiddleware = new LoggingMiddleware();

    $request = ['path' => '/test'];
    $called = false;

    $response = $loggingMiddleware->handle($request, function($req) use (&$called) {
        $called = true;
        return new ApiResponse('ok', 'Success');
    });

    return $called === true && $response->status === 'ok';
});

// Print results
echo "\n" . str_repeat("=", 60) . "\n";
echo "Test Results:\n";
echo "  ✅ Passed: $testsPassed\n";
echo "  ❌ Failed: $testsFailed\n";
echo "  Total: " . ($testsPassed + $testsFailed) . "\n";
echo str_repeat("=", 60) . "\n";

if ($testsFailed === 0) {
    echo "\n🎉 All tests passed!\n\n";
    exit(0);
} else {
    echo "\n⚠️  Some tests failed.\n\n";
    exit(1);
}
