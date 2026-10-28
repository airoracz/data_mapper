<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Cache\RedisCache;
use Predis\Client;

/**
 * RedisCache with a real Predis client against a real Redis server (127.0.0.1:6379).
 */
#[Group('redis')]
class RedisCachePredisTest extends TestCase
{
    private Client $client;

    private RedisCache $cache;

    protected function setUp(): void
    {
        $this->client = new Client(['host' => '127.0.0.1', 'port' => 6379, 'timeout' => 1.0]);
        try {
            $this->client->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis server is not running on 127.0.0.1:6379: ' . $e->getMessage());
        }

        $this->cache = new RedisCache($this->client, prefix: 'test_predis:');
        $this->cache->clear();
    }

    protected function tearDown(): void
    {
        if (isset($this->cache)) {
            $this->cache->clear();
        }
    }

    public function testBasicOperations(): void
    {
        $this->assertTrue($this->cache->set('a', ['x' => 1]));
        $this->assertSame(['x' => 1], $this->cache->get('a'));
        $this->assertTrue($this->cache->has('a'));
        $this->assertTrue($this->cache->set('t', 'v', 60));
        $this->assertGreaterThan(0, $this->cache->getTtl('t'));
        $this->assertTrue($this->cache->delete('a'));
        $this->assertFalse($this->cache->has('a'));
        $this->assertTrue($this->cache->ping());
        $this->assertSame('predis', $this->cache->getStats()['driver']);
    }

    public function testScanAcrossManyBatchesLeavesForeignKeys(): void
    {
        for ($i = 0; $i < 2500; $i++) {
            $this->cache->set("bulk{$i}", $i);
        }
        $this->client->set('foreign_predis:untouched', 'x');

        $this->assertSame(2500, $this->cache->size());
        $this->assertTrue($this->cache->clear());
        $this->assertSame(0, $this->cache->size());
        $this->assertSame('x', $this->client->get('foreign_predis:untouched'));
        $this->client->del(['foreign_predis:untouched']);
    }

    public function testSignedEntries(): void
    {
        $signed = new RedisCache($this->client, prefix: 'test_predis:', signingKey: 'secret');
        $signed->set('s', 'value');

        $this->assertSame('value', $signed->get('s'));
        $this->client->set('test_predis:s', serialize('injected'));
        $this->assertSame('default', $signed->get('s', 'default'));
    }
}
