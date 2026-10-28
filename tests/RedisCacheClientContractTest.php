<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Cache\RedisCache;

/**
 * Characterization of RedisCache against a Predis-style client (runs without the redis extension).
 * The phpredis path is covered by RedisCacheTest against a real server in CI.
 */
class RedisCacheClientContractTest extends TestCase
{
    private FakePredisClient $client;

    private RedisCache $cache;

    protected function setUp(): void
    {
        $this->client = new FakePredisClient();
        $this->cache = new RedisCache($this->client, prefix: 'p:', defaultTtl: 0);
    }

    public function testSetGetHasDelete(): void
    {
        $this->assertTrue($this->cache->set('a', ['x' => 1]));
        $this->assertSame(['x' => 1], $this->cache->get('a'));
        $this->assertTrue($this->cache->has('a'));
        $this->assertTrue($this->cache->delete('a'));
        $this->assertFalse($this->cache->delete('a'));
        $this->assertFalse($this->cache->has('a'));
        $this->assertSame('def', $this->cache->get('a', 'def'));
    }

    public function testFalseValueRoundTrips(): void
    {
        $this->cache->set('f', false);

        $this->assertFalse($this->cache->get('f', 'def'));
    }

    public function testSetWithTtlUsesSetex(): void
    {
        $this->assertTrue($this->cache->set('t', 'v', 60));

        $this->assertSame(['setex', 'p:t', 60], $this->client->lastWrite);
        $this->assertSame(60, $this->cache->getTtl('t'));
    }

    public function testFailedWriteReturnsFalse(): void
    {
        $this->client->failWrites = true;

        $this->assertFalse($this->cache->set('a', 1));
        $this->assertFalse($this->cache->set('a', 1, 10));
    }

    public function testClearSizeAndKeysUseOnlyOwnPrefix(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->client->data['other:z'] = serialize(3);

        $this->assertSame(2, $this->cache->size());
        $this->assertSame(['a', 'b'], $this->cache->keys());
        $this->assertTrue($this->cache->clear());
        $this->assertSame(0, $this->cache->size());
        $this->assertArrayHasKey('other:z', $this->client->data);
        $this->assertTrue($this->cache->clear());
    }

    public function testExpirePersistAndCounters(): void
    {
        $this->cache->set('n', 1);

        $this->assertTrue($this->cache->expire('n', 30));
        $this->assertTrue($this->cache->persist('n'));
        $this->assertFalse($this->cache->expire('missing', 30));
        $this->assertSame(5, $this->cache->increment('c', 5));
        $this->assertSame(3, $this->cache->decrement('c', 2));
    }

    public function testInfoPingAndStats(): void
    {
        $this->assertSame(['redis_version' => '7.0'], $this->cache->info());
        $this->assertTrue($this->cache->ping());
        $this->assertSame('predis', $this->cache->getStats()['driver']);
    }

    public function testClientErrorsAreSwallowed(): void
    {
        $this->client->throw = true;

        $this->assertSame('def', $this->cache->get('a', 'def'));
        $this->assertFalse($this->cache->set('a', 1));
        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->delete('a'));
        $this->assertFalse($this->cache->clear());
        $this->assertSame(0, $this->cache->size());
        $this->assertSame([], $this->cache->keys());
        $this->assertSame(-2, $this->cache->getTtl('a'));
        $this->assertFalse($this->cache->increment('a'));
        $this->assertSame([], $this->cache->info());
        $this->assertFalse($this->cache->ping());
    }

    public function testRejectsUnsupportedClient(): void
    {
        $this->expectException(\RuntimeException::class);

        new RedisCache(new \stdClass());
    }
}

/**
 * Minimal Predis-like client: set/setex return a status object (or null on failure),
 * expire/persist return int, keys() supports a trailing '*' pattern.
 */
class FakePredisClient
{
    /** @var array<string, string> */
    public array $data = [];

    /** @var array<string, int> */
    public array $ttl = [];

    public bool $failWrites = false;

    public bool $throw = false;

    /** @var array<int, mixed>|null */
    public ?array $lastWrite = null;

    private function guard(): void
    {
        if ($this->throw) {
            throw new \RuntimeException('connection lost');
        }
    }

    public function get(string $key): ?string
    {
        $this->guard();
        return $this->data[$key] ?? null;
    }

    public function set(string $key, string $value): ?object
    {
        $this->guard();
        $this->lastWrite = ['set', $key];
        if ($this->failWrites) {
            return null;
        }
        $this->data[$key] = $value;
        return new \stdClass();
    }

    public function setex(string $key, int $ttl, string $value): ?object
    {
        $this->guard();
        $this->lastWrite = ['setex', $key, $ttl];
        if ($this->failWrites) {
            return null;
        }
        $this->data[$key] = $value;
        $this->ttl[$key] = $ttl;
        return new \stdClass();
    }

    public function exists(string $key): int
    {
        $this->guard();
        return isset($this->data[$key]) ? 1 : 0;
    }

    /** @param array<int, string> $keys */
    public function del(array $keys): int
    {
        $this->guard();
        $deleted = 0;
        foreach ($keys as $key) {
            if (isset($this->data[$key])) {
                unset($this->data[$key]);
                $deleted++;
            }
        }
        return $deleted;
    }

    /** @return array<int, string> */
    public function keys(string $pattern): array
    {
        $this->guard();
        $prefix = rtrim($pattern, '*');
        return array_values(array_filter(array_keys($this->data), fn(string $k) => str_starts_with($k, $prefix)));
    }

    public function ttl(string $key): int
    {
        $this->guard();
        return $this->ttl[$key] ?? (isset($this->data[$key]) ? -1 : -2);
    }

    public function expire(string $key, int $ttl): int
    {
        $this->guard();
        return isset($this->data[$key]) ? 1 : 0;
    }

    public function persist(string $key): int
    {
        $this->guard();
        return isset($this->data[$key]) ? 1 : 0;
    }

    public function incrby(string $key, int $value): int
    {
        $this->guard();
        $this->data[$key] = (string) ((int) ($this->data[$key] ?? 0) + $value);
        return (int) $this->data[$key];
    }

    public function decrby(string $key, int $value): int
    {
        return $this->incrby($key, -$value);
    }

    /** @return array<string, string> */
    public function info(): array
    {
        $this->guard();
        return ['redis_version' => '7.0'];
    }

    public function ping(): string
    {
        $this->guard();
        return 'PONG';
    }
}
