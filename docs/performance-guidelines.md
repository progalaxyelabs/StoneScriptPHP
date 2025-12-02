# Performance Guidelines

This document provides comprehensive performance optimization guidelines for StoneScriptPHP applications. Following these practices ensures your API remains fast, scalable, and efficient.

## Table of Contents

- [Performance Principles](#performance-principles)
- [Database Optimization](#database-optimization)
- [Query Optimization](#query-optimization)
- [Caching Strategies](#caching-strategies)
- [Code Optimization](#code-optimization)
- [Resource Management](#resource-management)
- [API Response Optimization](#api-response-optimization)
- [Monitoring and Profiling](#monitoring-and-profiling)
- [Scalability Considerations](#scalability-considerations)
- [Load Testing](#load-testing)

---

## Performance Principles

### Measure Before Optimizing

Never optimize without measuring:

```php
function process(): ApiResponse
{
    $start_time = microtime(true);

    // Your code here
    $result = FnComplexOperation::run($this->param);

    $execution_time = (microtime(true) - $start_time) * 1000;
    log_debug("Operation took {$execution_time}ms");

    return res_ok(['result' => $result]);
}
```

### Optimize the Bottlenecks

Focus optimization efforts on:
1. Database queries (typically 60-80% of response time)
2. External API calls
3. Complex computations
4. Large data processing

### Performance Budget

Set performance targets:
- API response time: < 200ms (p95)
- Database queries: < 50ms (p95)
- Time to first byte: < 100ms
- Concurrent requests: > 100 req/s

---

## Database Optimization

### Database Connection Pooling

Use persistent connections:

```php
// Framework/Database.php
class Database
{
    private static ?PDO $connection = null;

    public static function get_instance(): PDO
    {
        if (self::$connection === null) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                Env::get('DB_HOST'),
                Env::get('DB_PORT'),
                Env::get('DB_NAME')
            );

            self::$connection = new PDO(
                $dsn,
                Env::get('DB_USER'),
                Env::get('DB_PASSWORD'),
                [
                    PDO::ATTR_PERSISTENT => true,  // Connection pooling
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        }

        return self::$connection;
    }
}
```

### Database Indexes

Create indexes for frequently queried columns:

```sql
-- users.pssql
CREATE TABLE users (
    user_id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add indexes for common queries
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_username ON users(username);
CREATE INDEX idx_users_status ON users(status);
CREATE INDEX idx_users_created_at ON users(created_at DESC);

-- Composite index for common filter combinations
CREATE INDEX idx_users_status_created ON users(status, created_at DESC);
```

### Analyze Query Performance

Use PostgreSQL's EXPLAIN:

```sql
-- Check query plan
EXPLAIN ANALYZE
SELECT * FROM users
WHERE status = 'active'
ORDER BY created_at DESC
LIMIT 20;

-- Look for:
-- - Seq Scan (bad for large tables) vs Index Scan (good)
-- - High cost values
-- - Missing indexes
```

### Partial Indexes

Create partial indexes for common filters:

```sql
-- Index only active users (if most queries filter by active)
CREATE INDEX idx_users_active ON users(created_at DESC)
WHERE status = 'active';

-- Index recent records only
CREATE INDEX idx_users_recent ON users(user_id)
WHERE created_at > CURRENT_DATE - INTERVAL '30 days';
```

---

## Query Optimization

### Avoid N+1 Queries

Use JOINs in database functions:

```sql
-- Bad - Requires N+1 queries (1 for posts + N for users)
-- First query
SELECT post_id, title, user_id FROM posts LIMIT 20;
-- Then N queries
SELECT username FROM users WHERE user_id = ?;

-- Good - Single query with JOIN
CREATE OR REPLACE FUNCTION fn_get_posts_with_users(
    p_limit INT DEFAULT 20,
    p_offset INT DEFAULT 0
)
RETURNS TABLE (
    post_id INT,
    title VARCHAR(255),
    content TEXT,
    username VARCHAR(50),
    user_email VARCHAR(255),
    created_at TIMESTAMP
)
LANGUAGE plpgsql
STABLE
AS $$
BEGIN
    RETURN QUERY
    SELECT
        p.post_id,
        p.title,
        p.content,
        u.username,
        u.email,
        p.created_at
    FROM posts p
    INNER JOIN users u ON p.user_id = u.user_id
    ORDER BY p.created_at DESC
    LIMIT p_limit
    OFFSET p_offset;
END;
$$;
```

### Limit Result Sets

Always paginate large result sets:

```sql
CREATE OR REPLACE FUNCTION fn_get_users(
    p_limit INT DEFAULT 20,
    p_offset INT DEFAULT 0
)
RETURNS TABLE (
    user_id INT,
    username VARCHAR(50),
    email VARCHAR(255)
)
LANGUAGE plpgsql
STABLE
AS $$
BEGIN
    -- Enforce maximum limit
    IF p_limit > 100 THEN
        p_limit := 100;
    END IF;

    RETURN QUERY
    SELECT u.user_id, u.username, u.email
    FROM users u
    ORDER BY u.created_at DESC
    LIMIT p_limit
    OFFSET p_offset;
END;
$$;
```

### Select Only Required Columns

```sql
-- Bad - SELECT *
SELECT * FROM users WHERE user_id = p_user_id;

-- Good - Select specific columns
SELECT user_id, username, email, created_at
FROM users
WHERE user_id = p_user_id;
```

### Use Appropriate Data Types

```sql
-- Good - Use appropriate types
CREATE TABLE orders (
    order_id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    amount NUMERIC(10, 2) NOT NULL,  -- For money
    status VARCHAR(20) NOT NULL,      -- For enum-like values
    created_at TIMESTAMP NOT NULL     -- For timestamps
);

-- Bad - Oversized types
CREATE TABLE orders (
    order_id BIGINT,           -- SERIAL is sufficient
    user_id VARCHAR(255),      -- INT is better
    amount VARCHAR(100),       -- Use NUMERIC for money
    status TEXT,               -- VARCHAR(20) is more efficient
    created_at VARCHAR(100)    -- Use TIMESTAMP
);
```

### Batch Operations

Perform batch operations efficiently:

```sql
CREATE OR REPLACE FUNCTION fn_batch_update_user_status(
    p_user_ids INT[],
    p_status VARCHAR(20)
)
RETURNS INT
LANGUAGE plpgsql
AS $$
DECLARE
    v_count INT;
BEGIN
    -- Update multiple users in one query
    UPDATE users
    SET status = p_status, updated_at = CURRENT_TIMESTAMP
    WHERE user_id = ANY(p_user_ids);

    GET DIAGNOSTICS v_count = ROW_COUNT;
    RETURN v_count;
END;
$$;
```

### Use Database Functions for Aggregations

```sql
-- Good - Let database handle aggregation
CREATE OR REPLACE FUNCTION fn_get_user_statistics(
    p_user_id INT
)
RETURNS TABLE (
    total_posts INT,
    total_comments INT,
    total_likes INT,
    avg_post_length NUMERIC
)
LANGUAGE plpgsql
STABLE
AS $$
BEGIN
    RETURN QUERY
    SELECT
        COUNT(DISTINCT p.post_id)::INT as total_posts,
        COUNT(DISTINCT c.comment_id)::INT as total_comments,
        COUNT(DISTINCT l.like_id)::INT as total_likes,
        AVG(LENGTH(p.content))::NUMERIC as avg_post_length
    FROM users u
    LEFT JOIN posts p ON u.user_id = p.user_id
    LEFT JOIN comments c ON u.user_id = c.user_id
    LEFT JOIN likes l ON u.user_id = l.user_id
    WHERE u.user_id = p_user_id;
END;
$$;

-- Bad - Fetching all data and aggregating in PHP
// $posts = FnGetUserPosts::run($user_id);
// $total = count($posts);  // Inefficient
```

---

## Caching Strategies

### APCu Caching

Use APCu for in-memory caching:

```php
class GetUserStatsRoute implements IRouteHandler
{
    public int $user_id;

    function validation_rules(): array
    {
        return [
            'user_id' => 'required|integer'
        ];
    }

    function process(): ApiResponse
    {
        $cache_key = "user_stats_{$this->user_id}";

        // Try to fetch from cache
        $cached = apcu_fetch($cache_key, $success);
        if ($success) {
            log_debug("Cache hit for $cache_key");
            return res_ok(['stats' => $cached, 'cached' => true]);
        }

        // Cache miss - query database
        log_debug("Cache miss for $cache_key");
        $stats = FnGetUserStatistics::run($this->user_id);

        // Store in cache for 5 minutes
        apcu_store($cache_key, $stats, 300);

        return res_ok(['stats' => $stats, 'cached' => false]);
    }
}
```

### Cache Invalidation

Invalidate cache when data changes:

```php
class UpdateUserProfileRoute implements IRouteHandler
{
    public int $user_id;
    public array $profile_data;

    function process(): ApiResponse
    {
        // Update database
        FnUpdateUserProfile::run($this->user_id, $this->profile_data);

        // Invalidate related caches
        apcu_delete("user_profile_{$this->user_id}");
        apcu_delete("user_stats_{$this->user_id}");
        apcu_delete("user_summary_{$this->user_id}");

        return res_ok(['message' => 'Profile updated']);
    }
}
```

### Cache Helper Functions

```php
namespace App\Utils;

class Cache
{
    /**
     * Get from cache or execute callback and cache result
     */
    public static function remember(string $key, int $ttl, callable $callback)
    {
        $cached = apcu_fetch($key, $success);

        if ($success) {
            return $cached;
        }

        $value = $callback();
        apcu_store($key, $value, $ttl);

        return $value;
    }

    /**
     * Cache tags for group invalidation
     */
    public static function tags(array $tags): self
    {
        return new self($tags);
    }

    /**
     * Invalidate all caches with a specific tag
     */
    public static function invalidateTag(string $tag): void
    {
        $tagged_keys = apcu_fetch("tag:$tag", $success);

        if ($success && is_array($tagged_keys)) {
            foreach ($tagged_keys as $key) {
                apcu_delete($key);
            }
            apcu_delete("tag:$tag");
        }
    }
}

// Usage
$user_data = Cache::remember("user_{$user_id}", 300, function() use ($user_id) {
    return FnGetUserData::run($user_id);
});
```

### Database Query Caching

PostgreSQL has built-in query caching, but you can also cache at application level:

```php
function process(): ApiResponse
{
    $cache_key = "popular_posts";

    $posts = Cache::remember($cache_key, 600, function() {
        // Expensive query cached for 10 minutes
        return FnGetPopularPosts::run(100);
    });

    return res_ok(['posts' => $posts]);
}
```

---

## Code Optimization

### Avoid Unnecessary Work

```php
// Bad - Always fetch even if not needed
function process(): ApiResponse
{
    $user = FnGetUserById::run($this->user_id);
    $profile = FnGetUserProfile::run($this->user_id);
    $settings = FnGetUserSettings::run($this->user_id);

    if ($this->type === 'basic') {
        return res_ok(['user' => $user]);
    }
    // profile and settings were fetched unnecessarily
}

// Good - Fetch only what's needed
function process(): ApiResponse
{
    $user = FnGetUserById::run($this->user_id);

    if ($this->type === 'basic') {
        return res_ok(['user' => $user]);
    }

    if ($this->type === 'full') {
        $profile = FnGetUserProfile::run($this->user_id);
        $settings = FnGetUserSettings::run($this->user_id);
        return res_ok(['user' => $user, 'profile' => $profile, 'settings' => $settings]);
    }

    return res_ok(['user' => $user]);
}
```

### Use Early Returns

```php
// Good - Early returns reduce nesting
function process(): ApiResponse
{
    if (!$this->validate_input()) {
        return e400('Invalid input');
    }

    if (!$this->check_permissions()) {
        return e403('Insufficient permissions');
    }

    $result = $this->perform_operation();
    return res_ok(['result' => $result]);
}
```

### Minimize String Operations

```php
// Bad - Multiple concatenations
$message = 'User ' . $username . ' with email ' . $email . ' created at ' . $timestamp;

// Good - Use sprintf
$message = sprintf('User %s with email %s created at %s', $username, $email, $timestamp);

// Best - Use array joining for large strings
$parts = ['User', $username, 'with email', $email, 'created at', $timestamp];
$message = implode(' ', $parts);
```

### Optimize Loops

```php
// Bad - Function call in loop condition
for ($i = 0; $i < count($items); $i++) {
    // count() called every iteration
}

// Good - Cache loop limit
$count = count($items);
for ($i = 0; $i < $count; $i++) {
    // count() called once
}

// Best - Use foreach when possible
foreach ($items as $item) {
    // More efficient and readable
}
```

### Lazy Loading

```php
class UserRoute implements IRouteHandler
{
    private ?array $user_cache = null;

    private function getUser(): array
    {
        if ($this->user_cache === null) {
            $this->user_cache = FnGetUserById::run($this->user_id);
        }
        return $this->user_cache;
    }

    function process(): ApiResponse
    {
        // User is only fetched when actually needed
        // and only once even if getUser() is called multiple times
        $user = $this->getUser();
        return res_ok(['user' => $user]);
    }
}
```

---

## Resource Management

### Memory Management

```php
function process(): ApiResponse
{
    // For large datasets, process in chunks
    $total_users = FnGetUserCount::run();
    $chunk_size = 1000;
    $processed = 0;

    for ($offset = 0; $offset < $total_users; $offset += $chunk_size) {
        $users = FnGetUsers::run($chunk_size, $offset);

        foreach ($users as $user) {
            // Process user
            $this->process_user($user);
        }

        $processed += count($users);

        // Free memory
        unset($users);
        gc_collect_cycles();
    }

    return res_ok(['processed' => $processed]);
}
```

### Connection Management

```php
function process(): ApiResponse
{
    try {
        $db = Database::get_instance();

        // Perform operations
        $result = FnComplexOperation::run($this->param);

        return res_ok(['result' => $result]);

    } finally {
        // Connection automatically returned to pool
        // No manual cleanup needed with PDO
    }
}
```

### File Handle Management

```php
function process(): ApiResponse
{
    $file_path = $this->getFilePath();

    $handle = fopen($file_path, 'r');
    if (!$handle) {
        return e500('Failed to open file');
    }

    try {
        $content = fread($handle, filesize($file_path));

        // Process content
        return res_ok(['content' => $content]);

    } finally {
        // Always close file handle
        fclose($handle);
    }
}
```

---

## API Response Optimization

### Response Compression

Enable gzip compression:

```php
// In bootstrap.php or Router
if (!ob_start('ob_gzhandler')) {
    ob_start();
}

header('Content-Encoding: gzip');
```

### Minimize Response Payload

```php
// Bad - Return unnecessary data
return res_ok([
    'user' => [
        'user_id' => 123,
        'username' => 'john',
        'email' => 'john@example.com',
        'password_hash' => '...', // Never return this!
        'internal_notes' => '...', // Internal data
        'temp_data' => null,
        'unused_field' => null
    ]
]);

// Good - Return only necessary data
return res_ok([
    'user' => [
        'user_id' => 123,
        'username' => 'john',
        'email' => 'john@example.com'
    ]
]);
```

### JSON Encoding Optimization

```php
// Use JSON_UNESCAPED_UNICODE for smaller payload
$json = json_encode($data, JSON_UNESCAPED_UNICODE);

// For large datasets, use streaming JSON
function streamJsonResponse(array $large_dataset): void
{
    header('Content-Type: application/json');
    echo '{"data":[';

    $first = true;
    foreach ($large_dataset as $item) {
        if (!$first) echo ',';
        echo json_encode($item);
        $first = false;

        // Flush output buffer periodically
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    echo ']}';
}
```

### Field Selection

Allow clients to specify required fields:

```php
class GetUserRoute implements IRouteHandler
{
    public int $user_id;
    public ?string $fields = null; // Comma-separated field list

    function process(): ApiResponse
    {
        $user = FnGetUserById::run($this->user_id);

        if ($this->fields) {
            // Return only requested fields
            $requested = array_map('trim', explode(',', $this->fields));
            $user = array_intersect_key($user, array_flip($requested));
        }

        return res_ok(['user' => $user]);
    }
}

// GET /users?user_id=123&fields=user_id,username,email
```

---

## Monitoring and Profiling

### Performance Logging

```php
function process(): ApiResponse
{
    global $timings;

    $timings['route_start'] = microtime(true);

    // Database operation
    $db_start = microtime(true);
    $result = FnComplexQuery::run($this->param);
    $timings['database'] = microtime(true) - $db_start;

    // Processing
    $process_start = microtime(true);
    $processed = $this->processData($result);
    $timings['processing'] = microtime(true) - $process_start;

    $timings['route_end'] = microtime(true);
    $total_time = ($timings['route_end'] - $timings['route_start']) * 1000;

    // Log performance metrics
    log_debug(sprintf(
        'Performance: Total=%dms, DB=%dms, Processing=%dms',
        $total_time,
        $timings['database'] * 1000,
        $timings['processing'] * 1000
    ));

    return res_ok(['data' => $processed]);
}
```

### Database Query Profiling

```sql
-- Enable query logging in PostgreSQL
-- postgresql.conf:
-- log_statement = 'all'
-- log_duration = on
-- log_min_duration_statement = 100  -- Log queries > 100ms

-- Analyze slow queries
SELECT query, calls, total_time, mean_time
FROM pg_stat_statements
ORDER BY mean_time DESC
LIMIT 20;
```

### APCu Cache Statistics

```php
function getCacheStats(): array
{
    $info = apcu_cache_info();
    $mem = apcu_sma_info();

    return [
        'hits' => $info['num_hits'],
        'misses' => $info['num_misses'],
        'hit_rate' => $info['num_hits'] / ($info['num_hits'] + $info['num_misses']),
        'memory_used' => $mem['seg_size'] - $mem['avail_mem'],
        'memory_available' => $mem['avail_mem'],
    ];
}
```

### Custom Metrics

```php
class MetricsCollector
{
    private static array $metrics = [];

    public static function track(string $metric, float $value): void
    {
        if (!isset(self::$metrics[$metric])) {
            self::$metrics[$metric] = [
                'count' => 0,
                'sum' => 0,
                'min' => PHP_FLOAT_MAX,
                'max' => PHP_FLOAT_MIN,
            ];
        }

        self::$metrics[$metric]['count']++;
        self::$metrics[$metric]['sum'] += $value;
        self::$metrics[$metric]['min'] = min(self::$metrics[$metric]['min'], $value);
        self::$metrics[$metric]['max'] = max(self::$metrics[$metric]['max'], $value);
    }

    public static function getMetrics(): array
    {
        $result = [];
        foreach (self::$metrics as $name => $data) {
            $result[$name] = [
                'count' => $data['count'],
                'avg' => $data['sum'] / $data['count'],
                'min' => $data['min'],
                'max' => $data['max'],
            ];
        }
        return $result;
    }
}

// Usage
$start = microtime(true);
$result = FnGetUsers::run();
MetricsCollector::track('db_query_time', microtime(true) - $start);
```

---

## Scalability Considerations

### Horizontal Scaling

Design for multiple instances:

```php
// Use database or Redis for session storage, not filesystem
// Use shared cache (Redis/Memcached) instead of APCu for multi-server

// Store sessions in database
CREATE TABLE sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    user_id INT,
    data TEXT,
    expires_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Stateless Design

Keep APIs stateless:

```php
// Good - Stateless (all info in request)
function process(): ApiResponse
{
    $token_data = JWT::decode($this->token);
    $user_id = $token_data['user_id'];

    // Process request with user_id
    return res_ok([]);
}

// Bad - Stateful (relies on server-side session)
function process(): ApiResponse
{
    session_start();
    $user_id = $_SESSION['user_id'];

    // This doesn't work well with load balancing
}
```

### Database Connection Pooling

Configure PostgreSQL for connection pooling:

```ini
# postgresql.conf
max_connections = 200
shared_buffers = 256MB
effective_cache_size = 1GB
work_mem = 4MB
```

Use PgBouncer for connection pooling:

```ini
# pgbouncer.ini
[databases]
myapp = host=localhost port=5432 dbname=myapp

[pgbouncer]
pool_mode = transaction
max_client_conn = 1000
default_pool_size = 25
```

---

## Load Testing

### Apache Bench

```bash
# Test endpoint with 1000 requests, 10 concurrent
ab -n 1000 -c 10 -H "Content-Type: application/json" \
   -p request.json \
   http://localhost:9100/api/endpoint

# View results
# Look for:
# - Requests per second
# - Time per request
# - Percentage of requests served within certain time
```

### wrk

```bash
# More advanced load testing
wrk -t4 -c100 -d30s --latency http://localhost:9100/api/endpoint

# Custom Lua script for complex scenarios
wrk -t4 -c100 -d30s -s script.lua http://localhost:9100
```

### Performance Targets

Set and measure against targets:

```
Target Performance Metrics:
- p50 (median): < 50ms
- p95: < 200ms
- p99: < 500ms
- Throughput: > 100 req/s
- Error rate: < 0.1%
- Database queries: < 10 per request
```

---

## Performance Checklist

### Database

- [ ] Indexes on frequently queried columns
- [ ] Composite indexes for multi-column queries
- [ ] Partial indexes for common filters
- [ ] Avoid SELECT *
- [ ] Use LIMIT for large result sets
- [ ] Batch operations where possible
- [ ] Analyze slow queries regularly
- [ ] Connection pooling configured
- [ ] Regular VACUUM and ANALYZE

### Caching

- [ ] Cache expensive queries
- [ ] Implement cache invalidation strategy
- [ ] Monitor cache hit rate (target > 80%)
- [ ] Use appropriate TTL values
- [ ] Cache at multiple levels (query, object, page)

### Code

- [ ] Minimize database calls
- [ ] Avoid N+1 queries
- [ ] Use early returns
- [ ] Optimize loops
- [ ] Lazy loading where appropriate
- [ ] Profile and optimize hot paths
- [ ] Minimize memory usage

### API

- [ ] Response compression enabled
- [ ] Minimize response payload
- [ ] Implement pagination
- [ ] Field selection support
- [ ] Rate limiting implemented
- [ ] Connection keep-alive enabled

### Infrastructure

- [ ] PHP OpCache enabled
- [ ] APCu installed and configured
- [ ] Proper resource limits set
- [ ] Load balancing configured
- [ ] CDN for static assets
- [ ] HTTP/2 enabled

---

## Related Documentation

- [Coding Standards](coding-standards.md)
- [API Design Guidelines](api-design-guidelines.md)
- [Security Best Practices](security-best-practices.md)
- [Getting Started Guide](getting-started.md)

---

## Performance Resources

- [PostgreSQL Performance Tips](https://wiki.postgresql.org/wiki/Performance_Optimization)
- [PHP Performance Tips](https://www.php.net/manual/en/intro.opcache.php)
- [Database Indexing Strategies](https://use-the-index-luke.com/)
- [APCu Documentation](https://www.php.net/manual/en/book.apcu.php)
