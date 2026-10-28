<?php

declare(strict_types=1);

namespace Tests;

/**
 * Minimal Predis-like client: set/setex return a status object (or null on failure),
 * expire/persist return int, keys() supports a trailing '*' pattern.
 */
class FakePredisClient
{
    /** @var array<string, string> */
    public array $data = [];

    /** @var array<string, int> */
    public array $ttl = [];

    public bool $failWrites = false;

    public bool $throw = false;

    /** @var array<int, mixed>|null */
    public ?array $lastWrite = null;

    private function guard(): void
    {
        if ($this->throw) {
            throw new \RuntimeException('connection lost');
        }
    }

    public function get(string $key): ?string
    {
        $this->guard();
        return $this->data[$key] ?? null;
    }

    public function set(string $key, string $value): ?object
    {
        $this->guard();
        $this->lastWrite = ['set', $key];
        if ($this->failWrites) {
            return null;
        }
        $this->data[$key] = $value;
        return new \stdClass();
    }

    public function setex(string $key, int $ttl, string $value): ?object
    {
        $this->guard();
        $this->lastWrite = ['setex', $key, $ttl];
        if ($this->failWrites) {
            return null;
        }
        $this->data[$key] = $value;
        $this->ttl[$key] = $ttl;
        return new \stdClass();
    }

    public function exists(string $key): int
    {
        $this->guard();
        return isset($this->data[$key]) ? 1 : 0;
    }

    /** @param array<int, string> $keys */
    public function del(array $keys): int
    {
        $this->guard();
        $deleted = 0;
        foreach ($keys as $key) {
            if (isset($this->data[$key])) {
                unset($this->data[$key]);
                $deleted++;
            }
        }
        return $deleted;
    }

    /** @return array<int, string> */
    public function keys(string $pattern): array
    {
        $this->guard();
        $prefix = rtrim($pattern, '*');
        return array_values(array_filter(array_keys($this->data), fn(string $k) => str_starts_with($k, $prefix)));
    }

    public function ttl(string $key): int
    {
        $this->guard();
        return $this->ttl[$key] ?? (isset($this->data[$key]) ? -1 : -2);
    }

    public function expire(string $key, int $ttl): int
    {
        $this->guard();
        return isset($this->data[$key]) ? 1 : 0;
    }

    public function persist(string $key): int
    {
        $this->guard();
        return isset($this->data[$key]) ? 1 : 0;
    }

    public function incrby(string $key, int $value): int
    {
        $this->guard();
        $this->data[$key] = (string) ((int) ($this->data[$key] ?? 0) + $value);
        return (int) $this->data[$key];
    }

    public function decrby(string $key, int $value): int
    {
        return $this->incrby($key, -$value);
    }

    /** @return array<string, string> */
    public function info(): array
    {
        $this->guard();
        return ['redis_version' => '7.0'];
    }

    public function ping(): string
    {
        $this->guard();
        return 'PONG';
    }
}
