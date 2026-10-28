<?php

declare(strict_types=1);

namespace Tests;

use DateInterval;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Cache\CacheInterface;
use Pocta\DataMapper\Cache\Psr16Cache;
use Pocta\DataMapper\Mapper;
use Psr\SimpleCache\CacheInterface as PsrCacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

class Psr16CacheTest extends TestCase
{
    private StrictPsr16Pool $pool;

    private Psr16Cache $cache;

    protected function setUp(): void
    {
        $this->pool = new StrictPsr16Pool();
        $this->cache = new Psr16Cache($this->pool);
    }

    public function testImplementsMapperCacheInterface(): void
    {
        $this->assertInstanceOf(CacheInterface::class, $this->cache);
    }

    public function testKeysWithPsrReservedCharactersWork(): void
    {
        $key = 'class_metadata:App\\Dto\\User';

        $this->assertTrue($this->cache->set($key, ['a' => 1]));
        $this->assertSame(['a' => 1], $this->cache->get($key));
        $this->assertTrue($this->cache->has($key));
        $this->assertTrue($this->cache->delete($key));
        $this->assertFalse($this->cache->has($key));
        $this->assertSame('default', $this->cache->get($key, 'default'));
    }

    public function testStoredNullAndFalseAreReturned(): void
    {
        $this->cache->set('null', null);
        $this->cache->set('false', false);

        $this->assertNull($this->cache->get('null', 'default'));
        $this->assertFalse($this->cache->get('false', 'default'));
    }

    public function testTtlIsPassedThrough(): void
    {
        $this->cache->set('k', 'v', 60);
        $this->cache->set('n', 'v');

        $this->assertSame([60, null], [$this->pool->lastTtls[0], $this->pool->lastTtls[1]]);
    }

    public function testDefaultTtlIsUsedWhenNoTtlGiven(): void
    {
        $cache = new Psr16Cache($this->pool, defaultTtl: 300);
        $cache->set('k', 'v');

        $this->assertSame(300, $this->pool->lastTtls[0]);
    }

    public function testClearDelegatesToPool(): void
    {
        $this->cache->set('k', 'v');

        $this->assertTrue($this->cache->clear());
        $this->assertFalse($this->cache->has('k'));
    }

    public function testMapperUsesPsr16Cache(): void
    {
        $mapper = new Mapper(cache: $this->cache);

        $rows = $mapper->fromCsv("name\nx\n", Psr16Row::class);

        $this->assertSame('x', $rows[0]->name);
        $this->assertNotSame([], $this->pool->items);
    }
}

class Psr16Row
{
    public string $name = '';
}

/**
 * In-memory PSR-16 pool that rejects reserved key characters like real implementations.
 */
class StrictPsr16Pool implements PsrCacheInterface
{
    /** @var array<string, mixed> */
    public array $items = [];

    /** @var list<int|null> */
    public array $lastTtls = [];

    private function check(string $key): void
    {
        if ($key === '' || preg_match('/[{}()\/\\\\@:]/', $key) === 1) {
            throw new class ("Invalid key '{$key}'") extends \InvalidArgumentException implements InvalidArgumentException {
            };
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->check($key);

        return array_key_exists($key, $this->items) ? $this->items[$key] : $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->check($key);
        $this->items[$key] = $value;
        $this->lastTtls[] = $ttl instanceof DateInterval ? -1 : $ttl;

        return true;
    }

    public function delete(string $key): bool
    {
        $this->check($key);
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    /** @return iterable<string, mixed> */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $this->check($key);

        return array_key_exists($key, $this->items);
    }
}
