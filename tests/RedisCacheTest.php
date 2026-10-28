<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Cache\RedisCache;
use Redis;

/**
 * Tests for RedisCache
 *
 * Note: These tests require Redis server running on localhost:6379
 * If Redis is not available, tests will be skipped
 *
 * To run Redis locally:
 * - macOS: brew install redis && brew services start redis
 * - Docker: docker run -d -p 6379:6379 redis:alpine
 * - Linux: sudo apt-get install redis-server && sudo service redis-server start
 */
#[Group('redis')]
class RedisCacheTest extends TestCase
{
    private RedisCache $cache;
    private Redis $redis;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension is not installed');
        }

        try {
            $this->redis = new Redis();
            $connected = @$this->redis->connect('127.0.0.1', 6379, 1.0);

            if (!$connected) {
                $this->markTestSkipped('Redis server is not running on localhost:6379');
            }

            // Ping to verify connection
            if (!$this->redis->ping()) {
                $this->markTestSkipped('Redis server is not responding');
            }

            $this->cache = new RedisCache($this->redis, prefix: 'test_mapper:', defaultTtl: 0);

            // Clear any existing test keys
            $this->cache->clear();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Could not connect to Redis: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->cache)) {
            $this->cache->clear();
        }

        if (isset($this->redis)) {
            $this->redis->close();
        }
    }

    public function testSetAndGet(): void
    {
        $this->cache->set('key1', 'value1');
        $this->assertSame('value1', $this->cache->get('key1'));
    }

    public function testGetWithDefault(): void
    {
        $this->assertNull($this->cache->get('nonexistent'));
        $this->assertSame('default', $this->cache->get('nonexistent', 'default'));
    }

    public function testHas(): void
    {
        $this->assertFalse($this->cache->has('key1'));
        $this->cache->set('key1', 'value1');
        $this->assertTrue($this->cache->has('key1'));
    }

    public function testDelete(): void
    {
        $this->cache->set('key1', 'value1');
        $this->assertTrue($this->cache->has('key1'));

        $this->assertTrue($this->cache->delete('key1'));
        $this->assertFalse($this->cache->has('key1'));
    }

    public function testDeleteNonExistentKey(): void
    {
        // Deleting non-existent key should return false
        $this->assertFalse($this->cache->delete('nonexistent'));
    }

    public function testClearSizeAndKeysAcrossManyScanBatches(): void
    {
        for ($i = 0; $i < 2500; $i++) {
            $this->cache->set("bulk{$i}", $i);
        }
        $this->redis->set('foreign:untouched', 'x');

        $this->assertSame(2500, $this->cache->size());
        $this->assertCount(2500, array_unique($this->cache->keys()));
        $this->assertTrue($this->cache->clear());
        $this->assertSame(0, $this->cache->size());
        $this->assertSame('x', $this->redis->get('foreign:untouched'));
        $this->redis->del('foreign:untouched');
    }

    public function testClear(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->cache->set('key3', 'value3');

        $this->assertTrue($this->cache->clear());

        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
        $this->assertFalse($this->cache->has('key3'));
    }

    public function testTtlExpiration(): void
    {
        $this->cache->set('key1', 'value1', 1);
        $this->assertTrue($this->cache->has('key1'));

        // Wait for expiration
        sleep(2);

        $this->assertFalse($this->cache->has('key1'));
        $this->assertNull($this->cache->get('key1'));
    }

    public function testNoExpiration(): void
    {
        $this->cache->set('key1', 'value1', 0); // 0 = no expiration
        $this->assertTrue($this->cache->has('key1'));

        sleep(1);

        $this->assertTrue($this->cache->has('key1'));
        $this->assertSame('value1', $this->cache->get('key1'));
    }

    public function testDefaultTtl(): void
    {
        $cache = new RedisCache($this->redis, prefix: 'test_ttl:', defaultTtl: 1);
        $cache->clear();

        $cache->set('key1', 'value1'); // Uses default TTL of 1 second
        $this->assertTrue($cache->has('key1'));

        sleep(2);

        $this->assertFalse($cache->has('key1'));

        $cache->clear();
    }

    public function testSize(): void
    {
        $this->assertSame(0, $this->cache->size());

        $this->cache->set('key1', 'value1');
        $this->assertSame(1, $this->cache->size());

        $this->cache->set('key2', 'value2');
        $this->assertSame(2, $this->cache->size());

        $this->cache->delete('key1');
        $this->assertSame(1, $this->cache->size());
    }

    public function testKeys(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->cache->set('key3', 'value3');

        $keys = $this->cache->keys();
        sort($keys);

        $this->assertSame(['key1', 'key2', 'key3'], $keys);
    }

    public function testGetStats(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        $stats = $this->cache->getStats();

        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('prefix', $stats);
        $this->assertArrayHasKey('ttl_default', $stats);
        $this->assertArrayHasKey('driver', $stats);
        $this->assertSame(2, $stats['total']);
        $this->assertSame('test_mapper:', $stats['prefix']);
        $this->assertSame('phpredis', $stats['driver']);
    }

    public function testComplexDataTypes(): void
    {
        $data = [
            'string' => 'test',
            'int' => 123,
            'float' => 45.67,
            'bool' => true,
            'null' => null,
            'array' => [1, 2, 3],
            'nested' => ['a' => 'b', 'c' => ['d' => 'e']],
        ];

        $this->cache->set('complex', $data);
        $retrieved = $this->cache->get('complex');

        $this->assertSame($data, $retrieved);
    }

    public function testSpecialCharactersInKey(): void
    {
        $key = 'key:with:colons:and-dashes_and.dots';
        $this->cache->set($key, 'value');
        $this->assertSame('value', $this->cache->get($key));
    }

    public function testGetTtl(): void
    {
        $this->cache->set('key1', 'value1', 10);
        $ttl = $this->cache->getTtl('key1');

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(10, $ttl);

        // Non-existent key
        $this->assertSame(-2, $this->cache->getTtl('nonexistent'));
    }

    public function testExpire(): void
    {
        $this->cache->set('key1', 'value1', 0); // No expiration
        $this->assertTrue($this->cache->expire('key1', 1)); // Set to 1 second

        sleep(2);

        $this->assertFalse($this->cache->has('key1'));
    }

    public function testPersist(): void
    {
        $this->cache->set('key1', 'value1', 10); // 10 seconds expiration
        $ttl = $this->cache->getTtl('key1');
        $this->assertGreaterThan(0, $ttl);

        $this->assertTrue($this->cache->persist('key1')); // Remove expiration

        $ttl = $this->cache->getTtl('key1');
        $this->assertSame(-1, $ttl); // -1 means no expiration
    }

    public function testIncrement(): void
    {
        $this->assertSame(1, $this->cache->increment('counter'));
        $this->assertSame(2, $this->cache->increment('counter'));
        $this->assertSame(5, $this->cache->increment('counter', 3));

        // Note: increment/decrement store raw values, not serialized
        // So we verify the final value by incrementing again
        $this->assertSame(6, $this->cache->increment('counter'));
    }

    public function testDecrement(): void
    {
        // First increment to set a raw integer value
        $this->cache->increment('counter', 10);

        $this->assertSame(9, $this->cache->decrement('counter'));
        $this->assertSame(8, $this->cache->decrement('counter'));
        $this->assertSame(5, $this->cache->decrement('counter', 3));

        // Verify by decrementing again
        $this->assertSame(4, $this->cache->decrement('counter'));
    }

    public function testInfo(): void
    {
        $info = $this->cache->info();

        // Redis info typically contains sections like 'Server', 'Clients', etc.
        $this->assertNotEmpty($info);
    }

    public function testPing(): void
    {
        $this->assertTrue($this->cache->ping());
    }

    public function testPrefixIsolation(): void
    {
        $cache1 = new RedisCache($this->redis, prefix: 'app1:');
        $cache2 = new RedisCache($this->redis, prefix: 'app2:');

        $cache1->set('key', 'value1');
        $cache2->set('key', 'value2');

        $this->assertSame('value1', $cache1->get('key'));
        $this->assertSame('value2', $cache2->get('key'));

        // Clear only affects own prefix
        $cache1->clear();
        $this->assertNull($cache1->get('key'));
        $this->assertSame('value2', $cache2->get('key'));

        $cache2->clear();
    }

    public function testObjectSerialization(): void
    {
        $obj = new \stdClass();
        $obj->name = 'Test';
        $obj->value = 123;

        $this->cache->set('object', $obj);
        $retrieved = $this->cache->get('object');

        $this->assertInstanceOf(\stdClass::class, $retrieved);
        $this->assertSame('Test', $retrieved->name);
        $this->assertSame(123, $retrieved->value);
    }

    public function testEmptyStringValue(): void
    {
        $this->cache->set('empty', '');
        $this->assertTrue($this->cache->has('empty'));
        $this->assertSame('', $this->cache->get('empty'));
    }

    public function testZeroValue(): void
    {
        $this->cache->set('zero_int', 0);
        $this->cache->set('zero_float', 0.0);
        $this->cache->set('zero_string', '0');

        $this->assertSame(0, $this->cache->get('zero_int'));
        $this->assertSame(0.0, $this->cache->get('zero_float'));
        $this->assertSame('0', $this->cache->get('zero_string'));
    }

    public function testFalseValue(): void
    {
        $this->cache->set('false', false);
        $this->assertTrue($this->cache->has('false'));
        $this->assertFalse($this->cache->get('false'));
    }

    public function testNullValue(): void
    {
        $this->cache->set('null', null);
        $this->assertTrue($this->cache->has('null'));
        $this->assertNull($this->cache->get('null'));
    }

    public function testInvalidRedisClientThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid Redis client');

        new RedisCache(new \stdClass());
    }
}
