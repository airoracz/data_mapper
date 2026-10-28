<?php

declare(strict_types=1);

namespace Tests;

use Attribute;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\Filters\FilterInterface;
use Pocta\DataMapper\Mapper;

/**
 * User-defined filters may depend on construction time/state (e.g. new DateTimeImmutable() in an
 * attribute argument), so they must be instantiated on every use - as before per-class caching.
 */
class CustomFilterInstantiationTest extends TestCase
{
    protected function setUp(): void
    {
        CountingFilter::$instances = 0;
    }

    public function testCustomFilterIsInstantiatedOnEveryNormalization(): void
    {
        $mapper = new Mapper();

        $first = $mapper->toArray(new CustomFilteredDto());
        $second = $mapper->toArray(new CustomFilteredDto());

        $this->assertNotSame($first['value'], $second['value']);
    }

    public function testCustomFilterIsInstantiatedOnEveryDenormalization(): void
    {
        $mapper = new Mapper();

        $first = $mapper->fromArray(['value' => 'v'], CustomFilteredDto::class);
        $second = $mapper->fromArray(['value' => 'v'], CustomFilteredDto::class);

        $this->assertNotSame($first->value, $second->value);
    }
}

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class CountingFilter implements FilterInterface
{
    public static int $instances = 0;

    private int $instance;

    public function __construct()
    {
        $this->instance = ++self::$instances;
    }

    public function apply(mixed $value): mixed
    {
        return is_string($value) ? $value . '#' . $this->instance : $value;
    }
}

class CustomFilteredDto
{
    #[CountingFilter]
    public string $value = 'v';
}
