# Cache System

[← Back to README](../README.md)

Data Mapper contains an advanced cache system for performance optimization. Cache stores class metadata (reflection data), which significantly speeds up repeated mapping of the same classes.

## Basic Usage

```php
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\Cache\ArrayCache;

// Default: ArrayCache (in-memory cache for single request)
$mapper = new Mapper();

// Explicit ArrayCache
$cache = new ArrayCache();
$mapper = new Mapper(cache: $cache);

// Mapping - metadata is automatically cached
$user = $mapper->fromArray(['id' => 1, 'name' => 'John'], User::class);
```

## Available Cache Implementations

### 1. ArrayCache (default)
In-memory cache, ideal for single-request caching:

```php
use Pocta\DataMapper\Cache\ArrayCache;

$cache = new ArrayCache();
$cache->set('key', 'value');
$value = $cache->get('key');        // 'value'
$exists = $cache->has('key');        // true
$size = $cache->size();              // Number of items
```

**Advantages**: Very fast, no dependencies
**Disadvantages**: Data is lost after request ends

### 2. FileCache
Persistent file-based cache, ideal for production:

```php
use Pocta\DataMapper\Cache\FileCache;

// Basic usage with default settings
$cache = new FileCache('/path/to/cache/directory');
$mapper = new Mapper(cache: $cache);

// With custom TTL (time to live in seconds)
$cache = new FileCache(
    cacheDir: '/var/cache/data-mapper',
    defaultTtl: 3600, // 1 hour
    extension: '.cache.php'
);

$mapper = new Mapper(cache: $cache);
```

**Features:**
- Persistent storage across requests
- TTL (time to live) support with automatic expiration
- Atomic writes to prevent race conditions
- Automatic cleanup of expired entries
- Cache statistics and monitoring

**Advanced operations:**

```php
$cache = new FileCache('/path/to/cache');

// Set with custom TTL
$cache->set('key', 'value', 3600); // Expires in 1 hour

// Set with no expiration
$cache->set('permanent', 'value', 0);

// Cleanup expired entries
$deleted = $cache->cleanup(); // Returns number of deleted entries

// Get cache statistics
$stats = $cache->getStats();
// [
//     'total' => 42,           // Number of cache files
//     'size_bytes' => 1024000, // Total size in bytes
//     'oldest' => 1640000000,  // Timestamp of oldest entry
//     'newest' => 1640100000   // Timestamp of newest entry
// ]

// Get all cache keys
$keys = $cache->keys();

// Get cache size
$size = $cache->size();
```

**Advantages**: Persistent, production-ready, automatic expiration
**Disadvantages**: Slower than in-memory cache, requires filesystem access

### 3. RedisCache
Distributed Redis cache, ideal for multi-server production environments:

```php
use Pocta\DataMapper\Cache\RedisCache;
use Redis;

// Using phpredis extension (recommended)
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

// Basic usage
$cache = new RedisCache($redis);
$mapper = new Mapper(cache: $cache);

// With custom prefix and TTL
$cache = new RedisCache(
    redis: $redis,
    prefix: 'mapper:',      // Prefix for all keys
    defaultTtl: 3600        // Default TTL: 1 hour
);

$mapper = new Mapper(cache: $cache);
```

**Using Predis library (alternative):**

```php
// composer require predis/predis
use Predis\Client;

$redis = new Client([
    'scheme' => 'tcp',
    'host'   => '127.0.0.1',
    'port'   => 6379,
]);

$cache = new RedisCache($redis, prefix: 'app:mapper:');
$mapper = new Mapper(cache: $cache);
```

**Features:**
- Distributed caching across multiple servers
- TTL (time to live) support with automatic expiration
- Key prefix isolation for multiple applications
- Atomic operations (increment/decrement)
- Supports both phpredis extension and Predis library
- Cache statistics and monitoring
- Connection health checks (ping)
- Fully tested with 28 unit tests in CI/CD

**Advanced operations:**

```php
$cache = new RedisCache($redis, prefix: 'myapp:');

// Set with custom TTL
$cache->set('key', 'value', 3600); // Expires in 1 hour

// Set with no expiration
$cache->set('permanent', 'value', 0);

// Get TTL for a key
$ttl = $cache->getTtl('key'); // Seconds remaining, -1 = no expiry, -2 = doesn't exist

// Change expiration time
$cache->expire('key', 7200); // Set to 2 hours

// Remove expiration (persist forever)
$cache->persist('key');

// Increment/Decrement (atomic operations)
$cache->increment('counter');        // +1
$cache->increment('counter', 5);     // +5
$cache->decrement('counter');        // -1
$cache->decrement('counter', 3);     // -3

// Get cache statistics
$stats = $cache->getStats();
// [
//     'total' => 42,              // Number of cache entries
//     'prefix' => 'myapp:',       // Key prefix
//     'ttl_default' => 3600,      // Default TTL in seconds
//     'driver' => 'phpredis'      // Driver type (phpredis or predis)
// ]

// Get all cache keys (without prefix)
$keys = $cache->keys();

// Get cache size
$size = $cache->size();

// Health check
$isConnected = $cache->ping(); // true/false

// Redis server info
$info = $cache->info();
```

**Production setup with persistence and clustering:**

```php
// Single Redis instance
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->auth('your-password');          // Optional authentication
$redis->select(1);                      // Use database 1
$cache = new RedisCache($redis, prefix: 'prod:mapper:', defaultTtl: 7200);

// Redis Sentinel (high availability)
$redis = new Redis();
$redis->connect('sentinel-host', 26379);
// Configure sentinel...

// Redis Cluster (for horizontal scaling)
// Requires redis-cluster support
```

**Advantages**:
- Fast in-memory performance
- Distributed across multiple servers
- Persistence to disk (optional)
- High availability with replication
- Horizontal scaling with clustering

**Disadvantages**:
- Requires Redis server
- Network latency for remote connections
- Additional infrastructure to maintain

### 4. NullCache
Disable caching (for debugging):

```php
use Pocta\DataMapper\Cache\NullCache;

$mapper = new Mapper(cache: new NullCache());
```

## Cache Integrity (signing)

Cache entries are unserialized when read. Anyone who can write to the cache storage (the cache directory, the
Redis server) could otherwise inject arbitrary objects. Pass a secret signing key - entries are HMAC-signed and
only entries with a valid signature are unserialized:

```php
$secret = $_ENV['DATA_MAPPER_CACHE_KEY']; // random, kept out of the cache storage

$cache = new FileCache('/var/cache/data-mapper', signingKey: $secret);
$cache = new RedisCache($redis, prefix: 'app:mapper:', signingKey: $secret);
```

- Without a key, behaviour is unchanged (unsigned entries).
- With a key, unsigned or tampered entries are treated as cache misses and rebuilt.
- `FileCache` files always start with `<?php exit; ?>`, so executing a cache file as PHP (e.g. a cache directory
  under the web root) does nothing. Files written by older versions are still read.

## Custom Cache Implementation

You can create custom cache adapters by implementing `CacheInterface`:

```php
use Pocta\DataMapper\Cache\CacheInterface;

class MemcachedCache implements CacheInterface
{
    public function __construct(private \Memcached $memcached, private string $prefix = 'mapper:') {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->memcached->get($this->prefix . $key);
        return $value !== false ? $value : $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->memcached->set($this->prefix . $key, $value, $ttl ?? 0);
    }

    public function has(string $key): bool
    {
        $this->memcached->get($this->prefix . $key);
        return $this->memcached->getResultCode() !== \Memcached::RES_NOTFOUND;
    }

    public function delete(string $key): bool
    {
        return $this->memcached->delete($this->prefix . $key);
    }

    public function clear(): bool
    {
        return $this->memcached->flush();
    }
}

// Usage
$memcached = new \Memcached();
$memcached->addServer('127.0.0.1', 11211);
$mapper = new Mapper(cache: new MemcachedCache($memcached));
```

## Cache Management

```php
// Clear cache for specific class
$mapper->clearCache(User::class);

// Clear entire cache
$mapper->clearCache();

// Access metadata factory
$factory = $mapper->getMetadataFactory();
$metadata = $factory->getMetadata(User::class);
```

## Performance Tips

1. **Choose the right cache backend**:

```php
// Development: ArrayCache (fastest, single request only)
$mapper = new Mapper(cache: new ArrayCache());

// Production (single server): FileCache (persistent, no dependencies)
$mapper = new Mapper(cache: new FileCache('/var/cache/data-mapper', 3600));

// Production (multi-server): RedisCache (distributed, scalable)
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$mapper = new Mapper(cache: new RedisCache($redis, prefix: 'app:mapper:', defaultTtl: 7200));
```

**Cache comparison:**

| Cache Type | Speed | Persistence | Multi-server | Use Case |
|------------|-------|-------------|--------------|----------|
| ArrayCache | Fastest | No | No | Development, testing |
| FileCache | Fast | Yes | No | Single-server production |
| RedisCache | Very Fast | Optional | Yes | Multi-server, clustering |
| NullCache | N/A | No | No | Debugging only |

2. **Cache warmup**: Pre-generate metadata at application startup

```php
// Cache warmup
$classes = [User::class, Product::class, Order::class];
foreach ($classes as $class) {
    $mapper->getMetadataFactory()->getMetadata($class);
}
```

3. **Periodic cleanup**: For FileCache, regularly cleanup expired entries

```php
// In a scheduled task (cron job)
$cache = new FileCache('/var/cache/data-mapper');
$deleted = $cache->cleanup();
echo "Cleaned up {$deleted} expired cache entries\n";

// Monitor cache size
$stats = $cache->getStats();
if ($stats['size_bytes'] > 100 * 1024 * 1024) { // 100MB
    echo "Warning: Cache is getting large ({$stats['size_bytes']} bytes)\n";
}
```
