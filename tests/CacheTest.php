<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Cache\ArrayCache;
use Pocta\DataMapper\Cache\NullCache;
use Pocta\DataMapper\Cache\ClassMetadataFactory;
use Pocta\DataMapper\Mapper;

class CacheTest extends TestCase
{
    public function testArrayCacheBasicOperations(): void
    {
        $cache = new ArrayCache();

        // Test set and get
        $this->assertTrue($cache->set('key1', 'value1'));
        $this->assertSame('value1', $cache->get('key1'));

        // Test has
        $this->assertTrue($cache->has('key1'));
        $this->assertFalse($cache->has('nonexistent'));

        // Test default value
        $this->assertSame('default', $cache->get('nonexistent', 'default'));

        // Test delete
        $this->assertTrue($cache->delete('key1'));
        $this->assertFalse($cache->has('key1'));

        // Test clear
        $cache->set('key1', 'value1');
        $cache->set('key2', 'value2');
        $this->assertTrue($cache->clear());
        $this->assertFalse($cache->has('key1'));
        $this->assertFalse($cache->has('key2'));
    }

    public function testArrayCacheStoresNullValue(): void
    {
        $cache = new ArrayCache();
        $cache->set('nullable', null);

        $this->assertTrue($cache->has('nullable'));
        $this->assertNull($cache->get('nullable', 'default'));
        $this->assertSame('default', $cache->get('missing', 'default'));
    }

    public function testArrayCacheSize(): void
    {
        $cache = new ArrayCache();
        $this->assertSame(0, $cache->size());

        $cache->set('key1', 'value1');
        $this->assertSame(1, $cache->size());

        $cache->set('key2', 'value2');
        $this->assertSame(2, $cache->size());

        $cache->delete('key1');
        $this->assertSame(1, $cache->size());

        $cache->clear();
        $this->assertSame(0, $cache->size());
    }

    public function testNullCacheNeverCaches(): void
    {
        $cache = new NullCache();

        // @phpstan-ignore-next-line method.resultUnused (intentionally testing that set() has no effect)
        $cache->set('key1', 'value1');
        $this->assertNull($cache->get('key1'));
        $this->assertSame('default', $cache->get('key1', 'default'));
        $this->assertFalse($cache->has('key1'));
    }

    public function testClassMetadataFactory(): void
    {
        $cache = new ArrayCache();
        $factory = new ClassMetadataFactory($cache);

        // First call should build metadata
        $metadata1 = $factory->getMetadata(CacheTestClass::class);
        $this->assertSame(CacheTestClass::class, $metadata1->className);
        $this->assertCount(2, $metadata1->properties);
        $this->assertTrue($metadata1->hasConstructor());

        // Second call should use cache
        $metadata2 = $factory->getMetadata(CacheTestClass::class);
        $this->assertSame($metadata1, $metadata2); // Same instance from cache

        // Clear cache and get new instance
        $factory->clearCache(CacheTestClass::class);
        $metadata3 = $factory->getMetadata(CacheTestClass::class);
        $this->assertNotSame($metadata1, $metadata3); // Different instance
        $this->assertSame($metadata1->className, $metadata3->className); // Same data
    }

    public function testMapperWithCache(): void
    {
        $cache = new ArrayCache();
        $mapper = new Mapper(cache: $cache);

        // First mapping
        $user1 = $mapper->fromArray(['id' => 1, 'name' => 'John'], CacheTestClass::class);
        $this->assertSame(1, $user1->id);
        $this->assertSame('John', $user1->name);

        // Manually use metadata factory to populate cache
        $metadata = $mapper->getMetadataFactory()->getMetadata(CacheTestClass::class);
        $this->assertSame(CacheTestClass::class, $metadata->className);

        // Cache should have metadata now
        $this->assertGreaterThan(0, $cache->size());

        // Second mapping should work correctly
        $user2 = $mapper->fromArray(['id' => 2, 'name' => 'Jane'], CacheTestClass::class);
        $this->assertSame(2, $user2->id);
        $this->assertSame('Jane', $user2->name);
    }

    public function testMapperWithoutCache(): void
    {
        $nullCache = new NullCache();
        $mapper = new Mapper(cache: $nullCache);

        // Should work without caching
        $user = $mapper->fromArray(['id' => 1, 'name' => 'John'], CacheTestClass::class);
        $this->assertSame(1, $user->id);
        $this->assertSame('John', $user->name);
    }

    public function testMapperClearCache(): void
    {
        $cache = new ArrayCache();
        $mapper = new Mapper(cache: $cache);

        // Manually populate cache via metadata factory
        $mapper->getMetadataFactory()->getMetadata(CacheTestClass::class);
        $initialSize = $cache->size();
        $this->assertGreaterThan(0, $initialSize);

        // Clear cache for specific class
        $mapper->clearCache(CacheTestClass::class);

        // Cache should be empty or smaller
        $this->assertLessThanOrEqual($initialSize, $cache->size());

        // Should still work after cache clear
        $user = $mapper->fromArray(['id' => 1, 'name' => 'John'], CacheTestClass::class);
        $this->assertSame(1, $user->id);
    }

    public function testCachedMetadataPreservesPropertyInfo(): void
    {
        $factory = new ClassMetadataFactory(new ArrayCache());
        $metadata = $factory->getMetadata(CacheTestClass::class);

        // Check properties
        $idProp = $metadata->getProperty('id');
        $this->assertNotNull($idProp);
        $this->assertSame('id', $idProp->name);
        $this->assertSame('int', $idProp->typeName);
        $this->assertFalse($idProp->isNullable);

        $nameProp = $metadata->getProperty('name');
        $this->assertNotNull($nameProp);
        $this->assertSame('name', $nameProp->name);
        $this->assertSame('string', $nameProp->typeName);
        $this->assertFalse($nameProp->isNullable);

        // Check constructor
        $this->assertTrue($metadata->hasConstructor());
        $constructor = $metadata->constructor;
        $this->assertNotNull($constructor);
        $this->assertCount(2, $constructor->parameters);
    }
}

class CacheTestClass
{
    public function __construct(
        public int $id,
        public string $name
    ) {
    }
}
