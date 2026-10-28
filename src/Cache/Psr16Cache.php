<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Cache;

use Psr\SimpleCache\CacheInterface as PsrCacheInterface;
use stdClass;

/**
 * Adapter for any PSR-16 cache (Symfony, Laravel, ...). Requires psr/simple-cache.
 *
 * Mapper keys (e.g. "class_metadata:App\Dto\User") contain characters PSR-16 reserves, so they are
 * hashed into "{prefix}{sha256}". Note that clear() clears the whole PSR-16 pool - use a dedicated
 * pool/namespace for the mapper if the pool is shared.
 */
class Psr16Cache implements CacheInterface
{
    private readonly stdClass $miss;

    /**
     * @param PsrCacheInterface $cache PSR-16 cache pool
     * @param string $prefix Prefix of the stored keys (PSR-16 safe characters only)
     * @param int $defaultTtl Default time to live in seconds (0 = no expiration)
     */
    public function __construct(
        private readonly PsrCacheInterface $cache,
        private readonly string $prefix = 'data_mapper.',
        private readonly int $defaultTtl = 0,
    ) {
        // PSR-16 cannot tell a stored null from a miss through the default value - use a unique sentinel
        $this->miss = new stdClass();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->cache->get($this->key($key), $this->miss);

        return $value === $this->miss ? $default : $value;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl ??= $this->defaultTtl;

        return $this->cache->set($this->key($key), $value, $ttl > 0 ? $ttl : null);
    }

    public function has(string $key): bool
    {
        return $this->cache->has($this->key($key));
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete($this->key($key));
    }

    public function clear(): bool
    {
        return $this->cache->clear();
    }

    private function key(string $key): string
    {
        return $this->prefix . hash('sha256', $key);
    }
}
