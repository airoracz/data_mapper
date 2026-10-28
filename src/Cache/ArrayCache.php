<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Cache;

/**
 * Simple in-memory array cache
 * Perfect for single request caching of reflection metadata
 * Data is lost after request ends
 */
class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $cache = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->cache) ? $this->cache[$key] : $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->cache[$key] = $value;
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->cache);
    }

    public function delete(string $key): bool
    {
        unset($this->cache[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->cache = [];
        return true;
    }

    /**
     * Get current cache size (for debugging/monitoring)
     */
    public function size(): int
    {
        return count($this->cache);
    }

    /**
     * Get all cache keys (for debugging)
     *
     * @return array<string>
     */
    public function keys(): array
    {
        return array_keys($this->cache);
    }
}
