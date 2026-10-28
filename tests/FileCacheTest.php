<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Cache\FileCache;
use RuntimeException;

class FileCacheTest extends TestCase
{
    private string $cacheDir;
    private FileCache $cache;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/data-mapper-test-' . uniqid();
        $this->cache = new FileCache($this->cacheDir);
    }

    protected function tearDown(): void
    {
        // Cleanup test cache directory
        if (is_dir($this->cacheDir)) {
            $this->deleteDirectory($this->cacheDir);
        }
    }

    public function testSetAndGet(): void
    {
        $this->assertTrue($this->cache->set('key1', 'value1'));
        $this->assertSame('value1', $this->cache->get('key1'));
    }

    public function testNullValueIsStoredAndReturned(): void
    {
        $this->cache->set('nullable', null);

        $this->assertNull($this->cache->get('nullable', 'default'));
        $this->assertTrue($this->cache->has('nullable'));
    }

    public function testFalsyValuesAreStoredAndReturned(): void
    {
        $this->cache->set('false', false);
        $this->cache->set('zero', 0);
        $this->cache->set('empty', '');

        $this->assertFalse($this->cache->get('false', 'default'));
        $this->assertSame(0, $this->cache->get('zero', 'default'));
        $this->assertSame('', $this->cache->get('empty', 'default'));
    }

    public function testGetWithDefault(): void
    {
        $this->assertSame('default', $this->cache->get('nonexistent', 'default'));
        $this->assertNull($this->cache->get('nonexistent'));
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
        $this->assertTrue($this->cache->delete('nonexistent'));
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
        // Set with 1 second TTL
        $this->cache->set('expiring', 'value', 1);
        $this->assertTrue($this->cache->has('expiring'));
        $this->assertSame('value', $this->cache->get('expiring'));

        // Wait for expiration
        sleep(2);

        $this->assertFalse($this->cache->has('expiring'));
        $this->assertNull($this->cache->get('expiring'));
    }

    public function testNoExpiration(): void
    {
        // Set with no TTL (0 = never expires)
        $this->cache->set('permanent', 'value', 0);
        $this->assertTrue($this->cache->has('permanent'));

        // Even after waiting, should still be there
        sleep(1);
        $this->assertTrue($this->cache->has('permanent'));
    }

    public function testDefaultTtl(): void
    {
        $cache = new FileCache($this->cacheDir, 1); // 1 second default TTL
        $cache->set('key', 'value'); // Uses default TTL

        $this->assertTrue($cache->has('key'));
        sleep(2);
        $this->assertFalse($cache->has('key'));
    }

    public function testPersistentStorage(): void
    {
        // Create cache and store value
        $cache1 = new FileCache($this->cacheDir);
        $cache1->set('persistent', 'value');

        // Create new cache instance (simulating new request)
        $cache2 = new FileCache($this->cacheDir);
        $this->assertSame('value', $cache2->get('persistent'));
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
        $this->assertEmpty($this->cache->keys());

        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        $keys = $this->cache->keys();
        $this->assertCount(2, $keys);
        $this->assertContains('key1', $keys);
        $this->assertContains('key2', $keys);
    }

    public function testCleanup(): void
    {
        $this->cache->set('permanent', 'value', 0);
        $this->cache->set('expiring1', 'value', 1);
        $this->cache->set('expiring2', 'value', 1);

        $this->assertSame(3, $this->cache->size());

        sleep(2);

        $deleted = $this->cache->cleanup();
        $this->assertSame(2, $deleted);
        $this->assertSame(1, $this->cache->size());
        $this->assertTrue($this->cache->has('permanent'));
    }

    public function testGetStats(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        $stats = $this->cache->getStats();

        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('size_bytes', $stats);
        $this->assertArrayHasKey('oldest', $stats);
        $this->assertArrayHasKey('newest', $stats);

        $this->assertSame(2, $stats['total']);
        $this->assertGreaterThan(0, $stats['size_bytes']);
        $this->assertIsInt($stats['oldest']);
        $this->assertIsInt($stats['newest']);
    }

    public function testComplexDataTypes(): void
    {
        $data = [
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'array' => ['nested', 'array'],
            'object' => (object) ['property' => 'value'],
        ];

        $this->cache->set('complex', $data);
        $retrieved = $this->cache->get('complex');

        $this->assertEquals($data, $retrieved);
    }

    public function testSpecialCharactersInKey(): void
    {
        $keys = [
            'key:with:colons',
            'key.with.dots',
            'key/with/slashes',
            'key\\with\\backslashes',
            'key with spaces',
        ];

        foreach ($keys as $key) {
            $this->cache->set($key, "value-{$key}");
            $this->assertTrue($this->cache->has($key));
            $this->assertSame("value-{$key}", $this->cache->get($key));
        }
    }

    public function testInvalidCacheDirectoryThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create cache directory');

        // Try to create cache in a location that doesn't allow writes
        new FileCache('/invalid/path/that/cannot/be/created');
    }

    public function testCorruptedCacheFileIsHandled(): void
    {
        $this->cache->set('corrupted', 'value');

        // Corrupt the cache file
        $files = glob($this->cacheDir . '/*');
        $this->assertNotEmpty($files);
        file_put_contents($files[0], 'corrupted data');

        // Should return default value for corrupted file
        $this->assertNull($this->cache->get('corrupted'));
        $this->assertFalse($this->cache->has('corrupted'));
    }

    public function testCustomExtension(): void
    {
        $cache = new FileCache($this->cacheDir, 0, '.custom.cache');
        $cache->set('key', 'value');

        $files = glob($this->cacheDir . '/*.custom.cache');
        $this->assertNotEmpty($files);
    }

    public function testAtomicWrite(): void
    {
        // Set a value multiple times quickly to test atomic writes
        for ($i = 0; $i < 10; $i++) {
            $this->cache->set('atomic', "value-{$i}");
        }

        // Should have the last value
        $this->assertSame('value-9', $this->cache->get('atomic'));

        // Should only have one cache file for this key
        $files = glob($this->cacheDir . '/atomic_*');
        $this->assertIsArray($files);
        $this->assertCount(1, $files);
    }

    public function testEmptyStatsWhenNoCacheFiles(): void
    {
        $stats = $this->cache->getStats();
        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['size_bytes']);
        $this->assertNull($stats['oldest']);
        $this->assertNull($stats['newest']);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
