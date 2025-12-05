<?php

namespace Framework;

use Exception;

class CacheTaggedStore
{
    private Cache $cache;
    private array $tags;

    public function __construct(Cache $cache, array $tags)
    {
        $this->cache = $cache;
        $this->tags = $tags;
    }

    private function getTaggedKey(string $key): string
    {
        $tagIds = [];

        foreach ($this->tags as $tag) {
            $tagKey = "tag:{$tag}:id";
            $tagId = $this->cache->get($tagKey);

            if ($tagId === null) {
                $tagId = uniqid('', true);
                $this->cache->forever($tagKey, $tagId);
            }

            $tagIds[] = $tagId;
        }

        $tagSignature = implode('|', $tagIds);
        return "tagged:{$tagSignature}:{$key}";
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->getTaggedKey($key), $default);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $taggedKey = $this->getTaggedKey($key);

        foreach ($this->tags as $tag) {
            $this->addKeyToTag($tag, $taggedKey);
        }

        return $this->cache->set($taggedKey, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete($this->getTaggedKey($key));
    }

    public function has(string $key): bool
    {
        return $this->cache->has($this->getTaggedKey($key));
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
        foreach ($this->tags as $tag) {
            $this->invalidateTag($tag);
        }

        return true;
    }

    private function addKeyToTag(string $tag, string $key): void
    {
        $redis = $this->cache->getRedis();
        if ($redis) {
            try {
                $redis->sAdd("tag:{$tag}:keys", $key);
            } catch (Exception $e) {
                error_log("Failed to add key to tag '{$tag}': " . $e->getMessage());
            }
        }
    }

    private function invalidateTag(string $tag): void
    {
        $tagKey = "tag:{$tag}:id";
        $newTagId = uniqid('', true);
        $this->cache->forever($tagKey, $newTagId);

        $redis = $this->cache->getRedis();
        if ($redis) {
            try {
                $keys = $redis->sMembers("tag:{$tag}:keys");
                if (!empty($keys)) {
                    $redis->del($keys);
                }
                $redis->del("tag:{$tag}:keys");
            } catch (Exception $e) {
                error_log("Failed to invalidate tag '{$tag}': " . $e->getMessage());
            }
        }
    }

    public function invalidate(): bool
    {
        return $this->flush();
    }
}
