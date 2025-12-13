# Redis Caching Integration

StoneScriptPHP provides a powerful Redis caching layer with support for cache tags, TTL management, and automatic invalidation.

## Installation

Ensure Redis is installed and running on your system:

```bash
# Ubuntu/Debian
sudo apt-get install redis-server

# macOS
brew install redis

# Start Redis
redis-server
```

Install the PHP Redis extension:

```bash
# Ubuntu/Debian
sudo apt-get install php-redis

# macOS
pecl install redis
```

## Configuration

Add the following settings to your `.env` file:

```ini
# Redis Configuration
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0
REDIS_PREFIX=stonescript:

# Cache Configuration
CACHE_ENABLED=true
CACHE_DEFAULT_TTL=3600
```

## Basic Usage

### Simple Cache Operations

```php
use Framework\CacheManager;

// Get cache instance
$cache = CacheManager::instance();

// Store data in cache
$cache->set('user:1', $userData, 3600);

// Retrieve from cache
$user = $cache->get('user:1');

// Check if key exists
if ($cache->has('user:1')) {
    // Key exists
}

// Delete from cache
$cache->delete('user:1');

// Clear all cache
$cache->clear();
```

### Using Helper Functions

```php
// Get cache instance
cache();

// Simple get/set
cache_set('key', 'value', 3600);
$value = cache_get('key', 'default');

// Remember pattern - fetch or execute callback
$users = cache_remember('all_users', function() {
    return Database::query('SELECT * FROM users')->fetchAll();
}, 3600);

// Forget (delete) key
cache_forget('key');

// Flush all cache
cache_flush();
```

## Advanced Features

### Cache Tags

Tags allow you to group related cache items and invalidate them together:

```php
// Store with tags
cache_tags(['users', 'admin'])->set('admin_users', $data, 3600);

// Retrieve tagged cache
$data = cache_tags(['users', 'admin'])->get('admin_users');

// Remember with tags
$posts = cache_tags(['posts', 'published'])->remember('published_posts', function() {
    return Database::query('SELECT * FROM posts WHERE status = ?', ['published'])->fetchAll();
}, 3600);

// Invalidate all cache items with specific tags
cache_tags(['users'])->flush();
```

### Automatic Invalidation

Set up automatic cache invalidation based on database operations:

```php
use Framework\CacheManager;

$invalidator = CacheManager::invalidator();

// Invalidate specific tags when a table changes
$invalidator->onChange('users', ['users', 'user_list']);

// Use a callback for dynamic invalidation
$invalidator->onUpdate('posts', function($data, $table, $operation) {
    return ['posts', 'post:' . $data['id']];
});

// Trigger invalidation manually
$invalidator->invalidate('users', 'update', ['id' => 123]);

// Invalidate by pattern
$invalidator->invalidatePattern('user:*');

// Invalidate by prefix
$invalidator->invalidateByPrefix('session:');
```

### Multiple Key Operations

```php
// Get multiple keys at once
$values = cache()->getMultiple(['key1', 'key2', 'key3'], 'default');

// Set multiple keys
cache()->setMultiple([
    'key1' => 'value1',
    'key2' => 'value2',
    'key3' => 'value3'
], 3600);

// Delete multiple keys
cache()->deleteMultiple(['key1', 'key2', 'key3']);
```

### Increment/Decrement

```php
// Increment counter
cache()->increment('page_views');
cache()->increment('page_views', 5);

// Decrement counter
cache()->decrement('stock_count');
cache()->decrement('stock_count', 3);
```

### Pull (Get and Delete)

```php
// Get value and remove it from cache
$token = cache()->pull('temp_token', null);
```

### Forever Storage

```php
// Store indefinitely (no TTL)
cache()->forever('config', $configData);

// Remember forever
$settings = cache()->rememberForever('app_settings', function() {
    return Database::query('SELECT * FROM settings')->fetchAll();
});
```

## Real-World Examples

### Caching Database Queries

```php
// Cache user by ID
function getUserById(int $userId): ?array
{
    return cache_remember("user:{$userId}", function() use ($userId) {
        return Database::query('SELECT * FROM users WHERE id = ?', [$userId])->fetch();
    }, 3600);
}

// Invalidate when user is updated
cache_invalidator()->onUpdate('users', function($data) {
    return ["users", "user:{$data['id']}"];
});
```

### Caching API Responses

```php
// Cache external API response
function getExternalData(string $endpoint): array
{
    $cacheKey = "api:" . md5($endpoint);

    return cache_remember($cacheKey, function() use ($endpoint) {
        $response = file_get_contents($endpoint);
        return json_decode($response, true);
    }, 1800);
}
```

### Session-Based Caching

```php
// Cache user-specific data
function getUserDashboard(int $userId): array
{
    return cache_tags(['dashboard', "user:{$userId}"])->remember(
        "dashboard:{$userId}",
        function() use ($userId) {
            return [
                'stats' => getUserStats($userId),
                'recent_activity' => getRecentActivity($userId),
                'notifications' => getNotifications($userId)
            ];
        },
        600
    );
}

// Invalidate user's dashboard when they perform an action
cache_tags(["user:{$userId}"])->flush();
```

### Rate Limiting

```php
function checkRateLimit(string $ip): bool
{
    $key = "rate_limit:{$ip}";
    $attempts = cache()->get($key, 0);

    if ($attempts >= 100) {
        return false;
    }

    cache()->increment($key);

    if ($attempts === 0) {
        cache()->set($key, 1, 3600);
    }

    return true;
}
```

### Fragment Caching

```php
// Cache rendered HTML fragments
function renderProductCard(int $productId): string
{
    return cache_tags(['products', "product:{$productId}"])->remember(
        "product_card:{$productId}",
        function() use ($productId) {
            $product = getProduct($productId);
            ob_start();
            include 'templates/product-card.php';
            return ob_get_clean();
        },
        7200
    );
}
```

## Custom Configuration

You can create custom cache instances with different configurations:

```php
use Framework\Cache;

// Create custom cache instance
$customCache = new Cache(
    host: '192.168.1.100',
    port: 6380,
    password: 'secret',
    database: 2,
    prefix: 'myapp:',
    defaultTtl: 7200,
    enabled: true
);

// Or configure the singleton
CacheManager::configure(
    host: '127.0.0.1',
    port: 6379,
    password: '',
    database: 1,
    prefix: 'app:',
    defaultTtl: 3600,
    enabled: true
);
```

## Best Practices

1. **Use meaningful cache keys**: Use descriptive keys with namespaces (e.g., `user:123`, `post:456:comments`)

2. **Set appropriate TTLs**: Don't cache forever unless necessary. Use shorter TTLs for frequently changing data.

3. **Use tags for related data**: Group related cache items with tags for easy invalidation.

4. **Invalidate on changes**: Always invalidate cache when underlying data changes.

5. **Handle cache failures gracefully**: Always provide default values and fallbacks.

6. **Monitor cache hit rates**: Use Redis monitoring tools to optimize cache usage.

7. **Use cache for expensive operations**: Cache database queries, API calls, and complex calculations.

8. **Avoid caching very large objects**: Break large datasets into smaller cached chunks.

## Troubleshooting

### Cache Not Working

Check if Redis is running:
```bash
redis-cli ping
# Should return: PONG
```

Verify PHP Redis extension:
```bash
php -m | grep redis
```

Check cache is enabled:
```php
if (cache()->isEnabled()) {
    echo "Cache is working";
} else {
    echo "Cache is disabled or unavailable";
}
```

### Clear All Cache

```bash
redis-cli FLUSHDB
```

Or from PHP:
```php
cache()->flush();
```

### Monitor Cache

```bash
redis-cli MONITOR
```

## API Reference

### Cache Class Methods

- `get(string $key, mixed $default = null): mixed`
- `set(string $key, mixed $value, ?int $ttl = null): bool`
- `delete(string $key): bool`
- `clear(): bool`
- `has(string $key): bool`
- `remember(string $key, callable $callback, ?int $ttl = null): mixed`
- `rememberForever(string $key, callable $callback): mixed`
- `pull(string $key, mixed $default = null): mixed`
- `forever(string $key, mixed $value): bool`
- `forget(string $key): bool`
- `increment(string $key, int $value = 1): int|false`
- `decrement(string $key, int $value = 1): int|false`
- `tags(array $tags): CacheTaggedStore`
- `getMultiple(array $keys, mixed $default = null): array`
- `setMultiple(array $values, ?int $ttl = null): bool`
- `deleteMultiple(array $keys): bool`

### CacheInvalidator Methods

- `onChange(string $table, callable|array $tagsOrCallback): void`
- `onInsert(string $table, callable|array $tagsOrCallback): void`
- `onUpdate(string $table, callable|array $tagsOrCallback): void`
- `onDelete(string $table, callable|array $tagsOrCallback): void`
- `invalidate(string $table, string $operation, array $data = []): void`
- `invalidatePattern(string $pattern): int`
- `invalidateByPrefix(string $prefix): int`
