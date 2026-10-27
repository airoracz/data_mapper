<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\MapperOptions;
use Pocta\DataMapper\Exceptions\ValidationException;

class StaticPropertyTest extends TestCase
{
    protected function setUp(): void
    {
        StaticPropertyDto::$counter = 'original';
        StaticPropertyCtorDto::$counter = 'original';
    }

    public function testNormalizeSkipsStaticProperties(): void
    {
        $mapper = new Mapper();

        $result = $mapper->toArray(new StaticPropertyDto());

        $this->assertSame(['name' => 'default'], $result);
    }

    public function testDenormalizeDoesNotOverwriteStaticProperty(): void
    {
        $mapper = new Mapper();

        $mapper->fromArray(['name' => 'John', 'counter' => 'HACKED'], StaticPropertyDto::class);

        $this->assertSame('original', StaticPropertyDto::$counter);
    }

    public function testDenormalizeWithConstructorDoesNotOverwriteStaticProperty(): void
    {
        $mapper = new Mapper();

        $mapper->fromArray(['name' => 'John', 'counter' => 'HACKED'], StaticPropertyCtorDto::class);

        $this->assertSame('original', StaticPropertyCtorDto::$counter);
    }

    public function testMergeDoesNotOverwriteStaticProperty(): void
    {
        $mapper = new Mapper();

        $mapper->merge(['counter' => 'HACKED'], new StaticPropertyDto());

        $this->assertSame('original', StaticPropertyDto::$counter);
    }

    public function testMergeInStrictModeRejectsStaticPropertyKeyAsUnknown(): void
    {
        $mapper = new Mapper(MapperOptions::withStrictMode());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Unknown key 'counter'");

        $mapper->merge(['counter' => 'HACKED'], new StaticPropertyDto());
    }

    public function testStrictModeRejectsStaticPropertyKeyAsUnknown(): void
    {
        $mapper = new Mapper(MapperOptions::withStrictMode());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Unknown key 'counter'");

        $mapper->fromArray(['name' => 'John', 'counter' => 'HACKED'], StaticPropertyDto::class);
    }
}

class StaticPropertyDto
{
    public static string $counter = 'original';

    public string $name = 'default';
}

class StaticPropertyCtorDto
{
    public static string $counter = 'original';

    public function __construct(
        public string $name,
    ) {
    }
}
