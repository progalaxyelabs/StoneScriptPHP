<?php

namespace StoneScriptPHP;

class CacheInvalidator
{
    private Cache $cache;
    private array $invalidationRules = [];

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    public function addRule(string $table, string $operation, callable|array $tagsOrCallback): void
    {
        if (!isset($this->invalidationRules[$table])) {
            $this->invalidationRules[$table] = [];
        }

        if (!isset($this->invalidationRules[$table][$operation])) {
            $this->invalidationRules[$table][$operation] = [];
        }

        $this->invalidationRules[$table][$operation][] = $tagsOrCallback;
    }

    public function onInsert(string $table, callable|array $tagsOrCallback): void
    {
        $this->addRule($table, 'insert', $tagsOrCallback);
    }

    public function onUpdate(string $table, callable|array $tagsOrCallback): void
    {
        $this->addRule($table, 'update', $tagsOrCallback);
    }

    public function onDelete(string $table, callable|array $tagsOrCallback): void
    {
        $this->addRule($table, 'delete', $tagsOrCallback);
    }

    public function onChange(string $table, callable|array $tagsOrCallback): void
    {
        $this->onInsert($table, $tagsOrCallback);
        $this->onUpdate($table, $tagsOrCallback);
        $this->onDelete($table, $tagsOrCallback);
    }

    public function invalidate(string $table, string $operation, array $data = []): void
    {
        if (!isset($this->invalidationRules[$table][$operation])) {
            return;
        }

        foreach ($this->invalidationRules[$table][$operation] as $rule) {
            if (is_array($rule)) {
                $this->invalidateTags($rule);
            } elseif (is_callable($rule)) {
                $tags = $rule($data, $table, $operation);
                if (is_array($tags)) {
                    $this->invalidateTags($tags);
                } elseif (is_string($tags)) {
                    $this->invalidateTags([$tags]);
                }
            }
        }
    }

    private function invalidateTags(array $tags): void
    {
        if (empty($tags)) {
            return;
        }

        $this->cache->tags($tags)->flush();
    }

    public function invalidatePattern(string $pattern): int
    {
        $redis = $this->cache->getRedis();
        if (!$redis) {
            return 0;
        }

        $deleted = 0;
        $iterator = null;

        while (true) {
            $keys = $redis->scan($iterator, $pattern, 100);

            if ($keys === false) {
                break;
            }

            if (!empty($keys)) {
                $redis->del($keys);
                $deleted += count($keys);
            }

            if ($iterator === 0) {
                break;
            }
        }

        return $deleted;
    }

    public function invalidateByPrefix(string $prefix): int
    {
        return $this->invalidatePattern($prefix . '*');
    }

    public function getRules(): array
    {
        return $this->invalidationRules;
    }
}
