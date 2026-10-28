<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Cache;

use Redis;
use RuntimeException;

/**
 * Redis-based cache implementation for distributed caching
 * Supports both phpredis extension and Predis library
 * Perfect for production environments with multiple servers
 *
 * Requirements:
 * - phpredis extension (https://github.com/phpredis/phpredis)
 *   OR
 * - predis/predis package (composer require predis/predis)
 *
 * Example with phpredis:
 * ```php
 * $redis = new Redis();
 * $redis->connect('127.0.0.1', 6379);
 * $cache = new RedisCache($redis, prefix: 'mapper:', defaultTtl: 3600);
 * ```
 *
 * Example with Predis:
 * ```php
 * $redis = new Predis\Client(['host' => '127.0.0.1', 'port' => 6379]);
 * $cache = new RedisCache($redis, prefix: 'mapper:', defaultTtl: 3600);
 * ```
 *
 */
class RedisCache implements CacheInterface
{
    /** @var Redis|object */
    private object $redis;
    private string $prefix;
    private int $defaultTtl;
    private bool $isPredis;

    private const SIGNATURE_PREFIX = 'sha256:';

    /** Keys requested per SCAN round-trip */
    private const SCAN_COUNT = 1000;

    /**
     * @param Redis|object $redis Redis client instance (phpredis or Predis)
     * @param string $prefix Key prefix to avoid collisions
     * @param int $defaultTtl Default time to live in seconds (0 = no expiration)
     * @param string|null $signingKey Secret for HMAC-signing values; with it, only values carrying a valid
     *                                signature are unserialized (no object injection via the Redis server)
     * @throws RuntimeException If Redis client is not valid
     */
    public function __construct(
        object $redis,
        string $prefix = 'mapper:',
        int $defaultTtl = 0,
        private readonly ?string $signingKey = null,
    ) {
        $this->validateRedisClient($redis);
        $this->redis = $redis;
        $this->prefix = $prefix;
        $this->defaultTtl = $defaultTtl;
        $this->isPredis = !($redis instanceof Redis);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $prefixedKey = $this->prefix . $key;

        try {
            $value = $this->redisGet($prefixedKey);

            if ($value === false || $value === null) {
                return $default;
            }

            if (!is_string($value)) {
                return $default;
            }

            if ($this->signingKey !== null) {
                $value = $this->verifySignature($value);
                if ($value === null) {
                    return $default;
                }
            }

            $data = @unserialize($value);

            // unserialize returns false on error, but also when the serialized value was false
            // We need to check if it was actually an error
            if ($data === false && $value !== serialize(false)) {
                return $default;
            }

            return $data;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $prefixedKey = $this->prefix . $key;
        $ttl = $ttl ?? $this->defaultTtl;

        try {
            $serialized = serialize($value);
            if ($this->signingKey !== null) {
                $serialized = self::SIGNATURE_PREFIX . hash_hmac('sha256', $serialized, $this->signingKey) . "\n" . $serialized;
            }

            if ($ttl > 0) {
                return $this->redisSetEx($prefixedKey, $ttl, $serialized);
            }

            return $this->redisSet($prefixedKey, $serialized);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function has(string $key): bool
    {
        $prefixedKey = $this->prefix . $key;

        try {
            return $this->redisExists($prefixedKey);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function delete(string $key): bool
    {
        $prefixedKey = $this->prefix . $key;

        try {
            $result = $this->redisDel($prefixedKey);
            return $result > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function clear(): bool
    {
        try {
            // Get all keys with our prefix
            $keys = $this->redisKeys($this->prefix . '*');

            if (empty($keys)) {
                return true;
            }

            // Delete all matching keys
            $this->redisDel(...$keys);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the number of cache entries with this prefix
     */
    public function size(): int
    {
        try {
            $keys = $this->redisKeys($this->prefix . '*');
            return count($keys);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Get all cache keys (without prefix)
     *
     * @return array<string>
     */
    public function keys(): array
    {
        try {
            $keys = $this->redisKeys($this->prefix . '*');
            $prefixLen = strlen($this->prefix);

            return array_map(
                fn(string $key) => substr($key, $prefixLen),
                $keys
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get cache statistics
     *
     * @return array{total: int, prefix: string, ttl_default: int, driver: string}
     */
    public function getStats(): array
    {
        return [
            'total' => $this->size(),
            'prefix' => $this->prefix,
            'ttl_default' => $this->defaultTtl,
            'driver' => $this->isPredis ? 'predis' : 'phpredis',
        ];
    }

    /**
     * Get TTL for a specific key (time to live in seconds)
     * Returns -1 if key has no expiry, -2 if key doesn't exist
     */
    public function getTtl(string $key): int
    {
        $prefixedKey = $this->prefix . $key;

        try {
            return $this->redisTtl($prefixedKey);
        } catch (\Throwable $e) {
            return -2;
        }
    }

    /**
     * Set expiration time for a key
     */
    public function expire(string $key, int $ttl): bool
    {
        $prefixedKey = $this->prefix . $key;

        try {
            return $this->redisExpire($prefixedKey, $ttl);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Persist a key (remove expiration)
     */
    public function persist(string $key): bool
    {
        $prefixedKey = $this->prefix . $key;

        try {
            return $this->redisPersist($prefixedKey);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Increment a numeric value
     */
    public function increment(string $key, int $value = 1): int|false
    {
        $prefixedKey = $this->prefix . $key;

        try {
            return $this->redisIncrBy($prefixedKey, $value);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Decrement a numeric value
     */
    public function decrement(string $key, int $value = 1): int|false
    {
        $prefixedKey = $this->prefix . $key;

        try {
            return $this->redisDecrBy($prefixedKey, $value);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get Redis client info
     *
     * @return array<string, mixed>
     */
    public function info(): array
    {
        try {
            return $this->redisInfo();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Ping Redis server
     */
    public function ping(): bool
    {
        try {
            $result = $this->redisPing();
            // Predis returns a Status object, phpredis true or a string
            if ($result instanceof \Stringable) {
                $result = (string) $result;
            }
            return $result === true || $result === '+PONG' || $result === 'PONG';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Validate that provided client is a valid Redis instance
     *
     * @param object $redis
     * @throws RuntimeException
     */
    private function validateRedisClient(object $redis): void
    {
        if ($redis instanceof Redis) {
            return; // phpredis is valid
        }

        // Predis implements commands via __call, so it is recognised by its interface, not by methods
        if (interface_exists(\Predis\ClientInterface::class) && $redis instanceof \Predis\ClientInterface) {
            return;
        }

        throw new RuntimeException(
            'Invalid Redis client. Expected Redis (phpredis) or Predis\Client instance.'
        );
    }

    /**
     * Returns the payload of a signed value, or null when the signature is missing or invalid.
     */
    private function verifySignature(string $value): ?string
    {
        if (!str_starts_with($value, self::SIGNATURE_PREFIX) || $this->signingKey === null) {
            return null;
        }

        $newline = strpos($value, "\n");
        if ($newline === false) {
            return null;
        }

        $signature = substr($value, strlen(self::SIGNATURE_PREFIX), $newline - strlen(self::SIGNATURE_PREFIX));
        $payload = substr($value, $newline + 1);

        return hash_equals(hash_hmac('sha256', $payload, $this->signingKey), $signature) ? $payload : null;
    }

    /**
     * phpredis SCAN round-trip; the iterator is updated by reference (0 when the iteration is complete).
     */
    private function phpredisScan(?int &$iterator, string $pattern): mixed
    {
        /** @phpstan-ignore method.notFound */
        return $this->redis->scan($iterator, $pattern, self::SCAN_COUNT);
    }

    // Wrapper methods for phpredis vs Predis compatibility. Both clients share the same method names
    // (PHP method names are case-insensitive); only return types differ for the writes below.

    private function redisGet(string $key): mixed
    {
        /** @phpstan-ignore method.notFound */
        return $this->redis->get($key);
    }

    private function redisSet(string $key, string $value): bool
    {
        /** @phpstan-ignore method.notFound */
        return $this->isWriteSuccessful($this->redis->set($key, $value));
    }

    private function redisSetEx(string $key, int $ttl, string $value): bool
    {
        /** @phpstan-ignore method.notFound */
        return $this->isWriteSuccessful($this->redis->setex($key, $ttl, $value));
    }

    /**
     * phpredis returns bool, Predis a status object (or null on failure).
     */
    private function isWriteSuccessful(mixed $result): bool
    {
        if ($this->isPredis) {
            return $result !== null && $result !== false;
        }

        return (bool) $result;
    }

    private function redisExists(string $key): bool
    {
        /** @phpstan-ignore method.notFound */
        return $this->redis->exists($key) > 0;
    }

    private function redisDel(string ...$keys): int
    {
        /** @phpstan-ignore method.notFound */
        return $this->redis->del($keys);
    }

    /**
     * Lists keys matching the pattern with SCAN - unlike KEYS it does not block the server
     * while walking the whole keyspace. SCAN may return a key more than once, so keys are deduplicated.
     *
     * @return array<int, string>
     */
    private function redisKeys(string $pattern): array
    {
        $keys = [];

        if ($this->isPredis) {
            $cursor = '0';
            do {
                /** @phpstan-ignore method.notFound */
                $result = $this->redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => self::SCAN_COUNT]);
                if (!is_array($result) || !isset($result[0], $result[1]) || !is_array($result[1])) {
                    break;
                }
                $cursor = (string) $result[0];
                foreach ($result[1] as $key) {
                    if (is_string($key)) {
                        $keys[$key] = true;
                    }
                }
            } while ($cursor !== '0');
        } else {
            // phpredis: the iterator is passed by reference and becomes 0 when the iteration is complete
            $iterator = null;
            do {
                $batch = $this->phpredisScan($iterator, $pattern);
                if (is_array($batch)) {
                    foreach ($batch as $key) {
                        if (is_string($key)) {
                            $keys[$key] = true;
                        }
                    }
                }
            } while (is_int($iterator) && $iterator > 0);
        }

        return array_keys($keys);
    }

    private function redisTtl(string $key): int
    {
        /** @phpstan-ignore method.notFound */
        return $this->redis->ttl($key);
    }

    private function redisExpire(string $key, int $ttl): bool
    {
        /** @phpstan-ignore method.notFound */
        $result = $this->redis->expire($key, $ttl);

        // Predis returns int 1/0, phpredis bool
        return $this->isPredis ? $result === 1 : $result;
    }

    private function redisPersist(string $key): bool
    {
        /** @phpstan-ignore method.notFound */
        $result = $this->redis->persist($key);

        // Predis returns int 1/0, phpredis bool
        return $this->isPredis ? $result === 1 : $result;
    }

    private function redisIncrBy(string $key, int $value): int|false
    {
        /** @phpstan-ignore method.notFound */
        return $this->redis->incrBy($key, $value);
    }

    private function redisDecrBy(string $key, int $value): int|false
    {
        /** @phpstan-ignore method.notFound */
        return $this->redis->decrBy($key, $value);
    }

    /**
     * @return array<mixed>
     */
    private function redisInfo(): array
    {
        /** @phpstan-ignore method.notFound */
        $result = $this->redis->info();

        return is_array($result) ? $result : [];
    }

    private function redisPing(): mixed
    {
        /** @phpstan-ignore method.notFound */
        return $this->redis->ping();
    }
}
