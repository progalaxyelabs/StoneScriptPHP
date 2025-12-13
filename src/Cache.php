<?php

namespace StoneScriptPHP;

use Redis;
use Exception;

class Cache
{
    private ?Redis $redis = null;
    private string $prefix;
    private int $defaultTtl;
    private bool $enabled;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        string $password = '',
        int $database = 0,
        string $prefix = 'stonescript:',
        int $defaultTtl = 3600,
        bool $enabled = true
    ) {
        $this->prefix = $prefix;
        $this->defaultTtl = $defaultTtl;
        $this->enabled = $enabled;

        if ($this->enabled) {
            try {
                $this->redis = new Redis();
                $this->redis->connect($host, $port);

                if (!empty($password)) {
                    $this->redis->auth($password);
                }

                $this->redis->select($database);
            } catch (Exception $e) {
                error_log("Redis connection failed: " . $e->getMessage());
                $this->enabled = false;
                $this->redis = null;
            }
        }
    }

    private function getKey(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->enabled || !$this->redis) {
            return $default;
        }

        try {
            $value = $this->redis->get($this->getKey($key));

            if ($value === false) {
                return $default;
            }

            return unserialize($value);
        } catch (Exception $e) {
            error_log("Cache get failed for key '{$key}': " . $e->getMessage());
            return $default;
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->enabled || !$this->redis) {
            return false;
        }

        $ttl = $ttl ?? $this->defaultTtl;

        try {
            $serialized = serialize($value);

            if ($ttl > 0) {
                return $this->redis->setex($this->getKey($key), $ttl, $serialized);
            } else {
                return $this->redis->set($this->getKey($key), $serialized);
            }
        } catch (Exception $e) {
            error_log("Cache set failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    public function delete(string $key): bool
    {
        if (!$this->enabled || !$this->redis) {
            return false;
        }

        try {
            return $this->redis->del($this->getKey($key)) > 0;
        } catch (Exception $e) {
            error_log("Cache delete failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    public function clear(): bool
    {
        if (!$this->enabled || !$this->redis) {
            return false;
        }

        try {
            return $this->redis->flushDB();
        } catch (Exception $e) {
            error_log("Cache clear failed: " . $e->getMessage());
            return false;
        }
    }

    public function has(string $key): bool
    {
        if (!$this->enabled || !$this->redis) {
            return false;
        }

        try {
            return $this->redis->exists($this->getKey($key)) > 0;
        } catch (Exception $e) {
            error_log("Cache exists check failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->remember($key, $callback, 0);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->delete($key);

        return $value;
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->set($key, $value, $ttl);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->set($key, $value, 0);
    }

    public function forget(string $key): bool
    {
        return $this->delete($key);
    }

    public function flush(): bool
    {
        return $this->clear();
    }

    public function increment(string $key, int $value = 1): int|false
    {
        if (!$this->enabled || !$this->redis) {
            return false;
        }

        try {
            return $this->redis->incrBy($this->getKey($key), $value);
        } catch (Exception $e) {
            error_log("Cache increment failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        if (!$this->enabled || !$this->redis) {
            return false;
        }

        try {
            return $this->redis->decrBy($this->getKey($key), $value);
        } catch (Exception $e) {
            error_log("Cache decrement failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    public function tags(array $tags): CacheTaggedStore
    {
        return new CacheTaggedStore($this, $tags);
    }

    public function getMultiple(array $keys, mixed $default = null): array
    {
        if (!$this->enabled || !$this->redis) {
            return array_fill_keys($keys, $default);
        }

        try {
            $prefixedKeys = array_map(fn($key) => $this->getKey($key), $keys);
            $values = $this->redis->mGet($prefixedKeys);

            $result = [];
            foreach ($keys as $index => $key) {
                $value = $values[$index];
                $result[$key] = ($value === false) ? $default : unserialize($value);
            }

            return $result;
        } catch (Exception $e) {
            error_log("Cache getMultiple failed: " . $e->getMessage());
            return array_fill_keys($keys, $default);
        }
    }

    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        if (!$this->enabled || !$this->redis) {
            return false;
        }

        try {
            $ttl = $ttl ?? $this->defaultTtl;

            foreach ($values as $key => $value) {
                $this->set($key, $value, $ttl);
            }

            return true;
        } catch (Exception $e) {
            error_log("Cache setMultiple failed: " . $e->getMessage());
            return false;
        }
    }

    public function deleteMultiple(array $keys): bool
    {
        if (!$this->enabled || !$this->redis) {
            return false;
        }

        try {
            $prefixedKeys = array_map(fn($key) => $this->getKey($key), $keys);
            $this->redis->del($prefixedKeys);
            return true;
        } catch (Exception $e) {
            error_log("Cache deleteMultiple failed: " . $e->getMessage());
            return false;
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->redis !== null;
    }

    public function getRedis(): ?Redis
    {
        return $this->redis;
    }

    public static function fromEnv(): self
    {
        return new self(
            host: $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            port: (int)($_ENV['REDIS_PORT'] ?? 6379),
            password: $_ENV['REDIS_PASSWORD'] ?? '',
            database: (int)($_ENV['REDIS_DATABASE'] ?? 0),
            prefix: $_ENV['REDIS_PREFIX'] ?? 'stonescript:',
            defaultTtl: (int)($_ENV['CACHE_DEFAULT_TTL'] ?? 3600),
            enabled: filter_var($_ENV['CACHE_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN)
        );
    }
}
