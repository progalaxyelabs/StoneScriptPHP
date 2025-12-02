<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Framework\CacheManager;
use Framework\Database;

// Example 1: Basic Cache Operations
echo "=== Example 1: Basic Cache Operations ===\n";

$cache = CacheManager::instance();

// Store data
$cache->set('greeting', 'Hello, World!', 60);
echo "Cached: " . $cache->get('greeting') . "\n";

// Check if exists
echo "Key exists: " . ($cache->has('greeting') ? 'Yes' : 'No') . "\n";

// Delete
$cache->delete('greeting');
echo "After delete exists: " . ($cache->has('greeting') ? 'Yes' : 'No') . "\n\n";

// Example 2: Remember Pattern
echo "=== Example 2: Remember Pattern ===\n";

$users = cache_remember('users_list', function() {
    echo "Fetching from database...\n";
    // Simulate database query
    return [
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
        ['id' => 3, 'name' => 'Charlie']
    ];
}, 300);

echo "Users: " . json_encode($users) . "\n";

// Second call will use cache
$cachedUsers = cache_remember('users_list', function() {
    echo "This won't be printed - using cache\n";
    return [];
}, 300);

echo "Cached users: " . json_encode($cachedUsers) . "\n\n";

// Example 3: Cache Tags
echo "=== Example 3: Cache Tags ===\n";

// Cache with tags
cache_tags(['users', 'admin'])->set('admin_users', [
    ['id' => 1, 'name' => 'Admin 1'],
    ['id' => 2, 'name' => 'Admin 2']
], 600);

cache_tags(['users', 'regular'])->set('regular_users', [
    ['id' => 3, 'name' => 'User 1'],
    ['id' => 4, 'name' => 'User 2']
], 600);

// Retrieve tagged cache
$admins = cache_tags(['users', 'admin'])->get('admin_users');
echo "Admin users: " . json_encode($admins) . "\n";

// Invalidate all cache with 'admin' tag
echo "Invalidating 'admin' tag...\n";
cache_tags(['admin'])->flush();

// Try to get after invalidation
$adminsAfterFlush = cache_tags(['users', 'admin'])->get('admin_users');
echo "Admin users after flush: " . ($adminsAfterFlush ? json_encode($adminsAfterFlush) : 'null') . "\n";

// Regular users still cached
$regularUsers = cache_tags(['users', 'regular'])->get('regular_users');
echo "Regular users (still cached): " . json_encode($regularUsers) . "\n\n";

// Example 4: Automatic Invalidation
echo "=== Example 4: Automatic Invalidation ===\n";

$invalidator = CacheManager::invalidator();

// Set up invalidation rules
$invalidator->onChange('users', ['users', 'user_list']);

$invalidator->onUpdate('posts', function($data) {
    echo "Post {$data['id']} updated, invalidating cache\n";
    return ['posts', "post:{$data['id']}"];
});

// Simulate a database update
echo "Simulating user update...\n";
$invalidator->invalidate('users', 'update', ['id' => 1]);

echo "Simulating post update...\n";
$invalidator->invalidate('posts', 'update', ['id' => 42]);

echo "\n";

// Example 5: Increment/Decrement
echo "=== Example 5: Counters ===\n";

cache()->set('page_views', 0);
echo "Initial views: " . cache()->get('page_views') . "\n";

cache()->increment('page_views');
cache()->increment('page_views');
cache()->increment('page_views', 5);

echo "After increments: " . cache()->get('page_views') . "\n";

cache()->decrement('page_views', 2);
echo "After decrement: " . cache()->get('page_views') . "\n\n";

// Example 6: Multiple Operations
echo "=== Example 6: Multiple Operations ===\n";

cache()->setMultiple([
    'key1' => 'value1',
    'key2' => 'value2',
    'key3' => 'value3'
], 300);

$values = cache()->getMultiple(['key1', 'key2', 'key3', 'missing_key'], 'default');
echo "Multiple get: " . json_encode($values) . "\n";

cache()->deleteMultiple(['key1', 'key2']);

$valuesAfterDelete = cache()->getMultiple(['key1', 'key2', 'key3'], 'deleted');
echo "After delete: " . json_encode($valuesAfterDelete) . "\n\n";

// Example 7: Rate Limiting
echo "=== Example 7: Rate Limiting ===\n";

function checkRateLimit(string $identifier, int $maxAttempts = 5, int $window = 60): bool {
    $key = "rate_limit:{$identifier}";
    $attempts = cache()->get($key, 0);

    if ($attempts >= $maxAttempts) {
        echo "Rate limit exceeded for {$identifier}\n";
        return false;
    }

    cache()->increment($key);

    if ($attempts === 0) {
        cache()->set($key, 1, $window);
    }

    echo "Request {$attempts}/{$maxAttempts} for {$identifier}\n";
    return true;
}

// Simulate requests
for ($i = 0; $i < 7; $i++) {
    checkRateLimit('user_123', 5, 60);
}

echo "\n";

// Example 8: Session Data Caching
echo "=== Example 8: Session Data ===\n";

function getUserSession(int $userId): array {
    return cache_tags(['session', "user:{$userId}"])->remember(
        "session:{$userId}",
        function() use ($userId) {
            echo "Creating new session for user {$userId}\n";
            return [
                'user_id' => $userId,
                'logged_in_at' => time(),
                'preferences' => ['theme' => 'dark', 'language' => 'en']
            ];
        },
        1800
    );
}

$session = getUserSession(42);
echo "Session: " . json_encode($session) . "\n";

// Get again (from cache)
$cachedSession = getUserSession(42);
echo "Cached session: " . json_encode($cachedSession) . "\n";

// Invalidate user sessions
echo "Logging out user...\n";
cache_tags(['user:42'])->flush();

echo "\n";

// Example 9: Cache Pull (Get and Delete)
echo "=== Example 9: Pull (One-Time Values) ===\n";

cache()->set('verification_token', 'abc123', 300);
echo "Token stored\n";

$token = cache()->pull('verification_token');
echo "Token retrieved: {$token}\n";

$tokenAgain = cache()->pull('verification_token', 'no token');
echo "Token after pull: {$tokenAgain}\n\n";

// Example 10: Pattern Invalidation
echo "=== Example 10: Pattern Invalidation ===\n";

cache()->set('user:1:profile', ['name' => 'Alice']);
cache()->set('user:2:profile', ['name' => 'Bob']);
cache()->set('user:3:profile', ['name' => 'Charlie']);
cache()->set('product:1', ['name' => 'Widget']);

echo "Cached 4 items\n";

$deleted = $invalidator->invalidatePattern('*user:*');
echo "Deleted {$deleted} items matching pattern 'user:*'\n";

echo "User profile exists: " . (cache()->has('user:1:profile') ? 'Yes' : 'No') . "\n";
echo "Product exists: " . (cache()->has('product:1') ? 'Yes' : 'No') . "\n\n";

// Example 11: Forever Storage
echo "=== Example 11: Forever Storage ===\n";

cache()->forever('app_config', [
    'version' => '1.0.0',
    'name' => 'StoneScriptPHP'
]);

$config = cache()->get('app_config');
echo "Config: " . json_encode($config) . "\n\n";

// Clean up
echo "=== Cleanup ===\n";
echo "Flushing all cache...\n";
cache()->flush();
echo "Done!\n";
