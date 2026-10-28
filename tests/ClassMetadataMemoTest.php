<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Cache\ArrayCache;
use Pocta\DataMapper\Cache\CacheInterface;
use Pocta\DataMapper\Cache\ClassMetadataFactory;

/**
 * A persistent cache backend (file, Redis) must not be hit for every getMetadata() call -
 * fromObject() and CSV mapping call it per object/row.
 */
class ClassMetadataMemoTest extends TestCase
{
    public function testBackendIsReadOncePerClass(): void
    {
        $backend = new CountingCache();
        $factory = new ClassMetadataFactory($backend);

        $first = $factory->getMetadata(MemoMetaDto::class);
        $second = $factory->getMetadata(MemoMetaDto::class);

        $this->assertSame($first, $second);
        $this->assertSame(1, $backend->gets);
    }

    public function testClearCacheForClassForcesReload(): void
    {
        $backend = new CountingCache();
        $factory = new ClassMetadataFactory($backend);

        $factory->getMetadata(MemoMetaDto::class);
        $factory->clearCache(MemoMetaDto::class);
        $factory->getMetadata(MemoMetaDto::class);

        $this->assertSame(2, $backend->gets);
    }

    public function testClearAllCacheForcesReload(): void
    {
        $backend = new CountingCache();
        $factory = new ClassMetadataFactory($backend);

        $factory->getMetadata(MemoMetaDto::class);
        $factory->clearCache();
        $factory->getMetadata(MemoMetaDto::class);

        $this->assertSame(2, $backend->gets);
    }
}

class CountingCache implements CacheInterface
{
    public int $gets = 0;

    private ArrayCache $inner;

    public function __construct()
    {
        $this->inner = new ArrayCache();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->gets++;

        return $this->inner->get($key, $default);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->inner->set($key, $value, $ttl);
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }
}

class MemoMetaDto
{
    public string $name = '';
}
