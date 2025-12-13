<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Cache;
use StoneScriptPHP\CacheManager;
use StoneScriptPHP\CacheTaggedStore;
use StoneScriptPHP\CacheInvalidator;

class CacheTest extends TestCase
{
    private Cache $cache;

    protected function setUp(): void
    {
        $this->cache = new Cache(
            host: '127.0.0.1',
            port: 6379,
            password: '',
            database: 15,
            prefix: 'test:',
            defaultTtl: 60,
            enabled: true
        );

        $this->cache->clear();
    }

    protected function tearDown(): void
    {
        $this->cache->clear();
    }

    public function testBasicSetAndGet(): void
    {
        $this->cache->set('test_key', 'test_value');
        $this->assertEquals('test_value', $this->cache->get('test_key'));
    }

    public function testGetWithDefault(): void
    {
        $this->assertEquals('default', $this->cache->get('nonexistent', 'default'));
    }

    public function testHas(): void
    {
        $this->cache->set('exists', 'value');
        $this->assertTrue($this->cache->has('exists'));
        $this->assertFalse($this->cache->has('not_exists'));
    }

    public function testDelete(): void
    {
        $this->cache->set('to_delete', 'value');
        $this->assertTrue($this->cache->has('to_delete'));

        $this->cache->delete('to_delete');
        $this->assertFalse($this->cache->has('to_delete'));
    }

    public function testRemember(): void
    {
        $callCount = 0;

        $callback = function() use (&$callCount) {
            $callCount++;
            return 'computed_value';
        };

        $value1 = $this->cache->remember('remember_key', $callback);
        $this->assertEquals('computed_value', $value1);
        $this->assertEquals(1, $callCount);

        $value2 = $this->cache->remember('remember_key', $callback);
        $this->assertEquals('computed_value', $value2);
        $this->assertEquals(1, $callCount);
    }

    public function testIncrement(): void
    {
        $this->cache->set('counter', 0);
        $this->cache->increment('counter');
        $this->assertEquals(1, $this->cache->get('counter'));

        $this->cache->increment('counter', 5);
        $this->assertEquals(6, $this->cache->get('counter'));
    }

    public function testDecrement(): void
    {
        $this->cache->set('counter', 10);
        $this->cache->decrement('counter');
        $this->assertEquals(9, $this->cache->get('counter'));

        $this->cache->decrement('counter', 3);
        $this->assertEquals(6, $this->cache->get('counter'));
    }

    public function testPull(): void
    {
        $this->cache->set('pull_key', 'pull_value');

        $value = $this->cache->pull('pull_key');
        $this->assertEquals('pull_value', $value);

        $this->assertFalse($this->cache->has('pull_key'));
    }

    public function testForever(): void
    {
        $this->cache->forever('forever_key', 'forever_value');
        $this->assertEquals('forever_value', $this->cache->get('forever_key'));
    }

    public function testMultipleOperations(): void
    {
        $this->cache->setMultiple([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3'
        ]);

        $values = $this->cache->getMultiple(['key1', 'key2', 'key3', 'key4'], 'default');

        $this->assertEquals('value1', $values['key1']);
        $this->assertEquals('value2', $values['key2']);
        $this->assertEquals('value3', $values['key3']);
        $this->assertEquals('default', $values['key4']);

        $this->cache->deleteMultiple(['key1', 'key2']);
        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
        $this->assertTrue($this->cache->has('key3'));
    }

    public function testTags(): void
    {
        $taggedStore = $this->cache->tags(['tag1', 'tag2']);
        $this->assertInstanceOf(CacheTaggedStore::class, $taggedStore);

        $taggedStore->set('tagged_key', 'tagged_value');
        $this->assertEquals('tagged_value', $taggedStore->get('tagged_key'));

        $taggedStore->flush();
        $this->assertNull($taggedStore->get('tagged_key'));
    }

    public function testCacheInvalidator(): void
    {
        $invalidator = new CacheInvalidator($this->cache);

        $invalidator->onChange('users', ['users', 'user_list']);

        $rules = $invalidator->getRules();
        $this->assertArrayHasKey('users', $rules);
        $this->assertArrayHasKey('insert', $rules['users']);
        $this->assertArrayHasKey('update', $rules['users']);
        $this->assertArrayHasKey('delete', $rules['users']);
    }

    public function testCacheInvalidatorWithCallback(): void
    {
        $invalidator = new CacheInvalidator($this->cache);

        $invalidator->onUpdate('posts', function($data) {
            return ["post:{$data['id']}"];
        });

        $this->cache->tags(['post:123'])->set('post_data', 'some data');

        $invalidator->invalidate('posts', 'update', ['id' => 123]);

        $this->assertNull($this->cache->tags(['post:123'])->get('post_data'));
    }

    public function testCacheManager(): void
    {
        CacheManager::setInstance($this->cache);

        $instance = CacheManager::instance();
        $this->assertSame($this->cache, $instance);

        $invalidator = CacheManager::invalidator();
        $this->assertInstanceOf(CacheInvalidator::class, $invalidator);

        CacheManager::reset();
    }

    public function testIsEnabled(): void
    {
        $this->assertTrue($this->cache->isEnabled());

        $disabledCache = new Cache(enabled: false);
        $this->assertFalse($disabledCache->isEnabled());
    }

    public function testCacheSerializesData(): void
    {
        $data = [
            'string' => 'test',
            'number' => 123,
            'array' => [1, 2, 3],
            'nested' => ['key' => 'value']
        ];

        $this->cache->set('complex_data', $data);
        $retrieved = $this->cache->get('complex_data');

        $this->assertEquals($data, $retrieved);
    }
}
