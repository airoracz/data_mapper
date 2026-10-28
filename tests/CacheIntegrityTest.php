<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Cache\FileCache;
use Pocta\DataMapper\Cache\RedisCache;

/**
 * Cache backends unserialize stored data. Whoever can write to the cache storage must not be able
 * to inject objects (with signing enabled), and FileCache files must be harmless if executed as PHP.
 */
class CacheIntegrityTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dm-cache-integrity-' . uniqid();
        InjectionProbe::$wokenUp = false;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function onlyFile(): string
    {
        $files = glob($this->dir . '/*.cache.php') ?: [];
        $this->assertCount(1, $files);

        return $files[0];
    }

    // --- FileCache: executing a cache file as PHP ---

    public function testCacheFileStartsWithExitGuard(): void
    {
        (new FileCache($this->dir))->set('k', 'v');

        $this->assertStringStartsWith('<?php exit; ?>', (string) file_get_contents($this->onlyFile()));
    }

    public function testIncludingCacheFileExecutesNothing(): void
    {
        (new FileCache($this->dir))->set('k', "<?php echo 'PWNED'; ?>");

        // Executed in a separate process - the guard calls exit
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->onlyFile()) . ' 2>&1');

        $this->assertSame('', (string) $output);
    }

    public function testLegacyFileWithoutGuardIsStillRead(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('k', 'v');
        $file = $this->onlyFile();
        file_put_contents($file, serialize(['key' => 'k', 'value' => 'legacy', 'expires' => 0, 'created' => time()]));

        $this->assertSame('legacy', $cache->get('k'));
        $this->assertTrue($cache->has('k'));
    }

    // --- FileCache: signing ---

    public function testSignedFileCacheRoundTrip(): void
    {
        $cache = new FileCache($this->dir, signingKey: 'secret');
        $cache->set('k', ['a' => 1]);

        $this->assertSame(['a' => 1], $cache->get('k'));
        $this->assertTrue($cache->has('k'));
    }

    public function testSignedFileCacheRejectsTamperedFileWithoutUnserializing(): void
    {
        $cache = new FileCache($this->dir, signingKey: 'secret');
        $cache->set('k', 'v');
        $payload = serialize(['key' => 'k', 'value' => new InjectionProbe(), 'expires' => 0, 'created' => time()]);
        file_put_contents($this->onlyFile(), "<?php exit; ?>\nsha256:" . str_repeat('0', 64) . "\n" . $payload);

        $this->assertSame('default', $cache->get('k', 'default'));
        $this->assertFalse($cache->has('k'));
        $this->assertFalse(InjectionProbe::$wokenUp);
    }

    public function testSignedFileCacheRejectsUnsignedLegacyFile(): void
    {
        $cache = new FileCache($this->dir, signingKey: 'secret');
        $cache->set('k', 'v');
        file_put_contents($this->onlyFile(), serialize(['key' => 'k', 'value' => new InjectionProbe(), 'expires' => 0, 'created' => time()]));

        $this->assertSame('default', $cache->get('k', 'default'));
        $this->assertFalse(InjectionProbe::$wokenUp);
    }

    public function testEntrySignedWithOtherKeyIsRejected(): void
    {
        (new FileCache($this->dir, signingKey: 'key-a'))->set('k', 'v');

        $this->assertSame('default', (new FileCache($this->dir, signingKey: 'key-b'))->get('k', 'default'));
    }

    // --- RedisCache: signing ---

    public function testSignedRedisCacheRoundTrip(): void
    {
        $cache = new RedisCache(new FakePredisClient(), signingKey: 'secret');
        $cache->set('k', ['a' => 1]);

        $this->assertSame(['a' => 1], $cache->get('k'));
    }

    public function testSignedRedisCacheRejectsInjectedValueWithoutUnserializing(): void
    {
        $client = new FakePredisClient();
        $cache = new RedisCache($client, prefix: 'p:', signingKey: 'secret');
        $client->data['p:k'] = serialize(new InjectionProbe());

        $this->assertSame('default', $cache->get('k', 'default'));
        $this->assertFalse(InjectionProbe::$wokenUp);
    }

    public function testUnsignedRedisCacheIsUnchanged(): void
    {
        $client = new FakePredisClient();
        $cache = new RedisCache($client, prefix: 'p:');
        $cache->set('k', 'v');

        $this->assertSame(serialize('v'), $client->data['p:k']);
        $this->assertSame('v', $cache->get('k'));
    }
}

class InjectionProbe
{
    public static bool $wokenUp = false;

    public function __wakeup(): void
    {
        self::$wokenUp = true;
    }
}
