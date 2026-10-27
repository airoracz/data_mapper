<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\MapperOptions;
use Pocta\DataMapper\Exceptions\ValidationException;

class StrictModePathTest extends TestCase
{
    private function createMapper(): Mapper
    {
        return new Mapper(MapperOptions::withStrictMode());
    }

    public function testStrictModeAcceptsRootKeyOfPropertyPath(): void
    {
        $dto = $this->createMapper()->fromArray(['address' => ['city' => 'Prague']], StrictPathPropertyDto::class);

        $this->assertSame('Prague', $dto->city);
    }

    public function testStrictModeAcceptsRootKeyOfConstructorParameterPath(): void
    {
        $dto = $this->createMapper()->fromArray(['address' => ['city' => 'Prague']], StrictPathCtorDto::class);

        $this->assertSame('Prague', $dto->city);
    }

    public function testStrictModeAcceptsRootKeyOfIndexedPath(): void
    {
        $dto = $this->createMapper()->fromArray(
            ['addresses' => [['street' => 'Main']]],
            StrictPathIndexedDto::class
        );

        $this->assertSame('Main', $dto->firstStreet);
    }

    public function testStrictModeStillRejectsUnknownSiblingOfPathRoot(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Unknown key 'other'");

        $this->createMapper()->fromArray(
            ['address' => ['city' => 'Prague'], 'other' => 'x'],
            StrictPathPropertyDto::class
        );
    }
}

class StrictPathPropertyDto
{
    #[MapProperty(path: 'address.city')]
    public string $city;
}

class StrictPathCtorDto
{
    public function __construct(
        #[MapProperty(path: 'address.city')]
        public string $city,
    ) {
    }
}

class StrictPathIndexedDto
{
    #[MapProperty(path: 'addresses[0].street')]
    public string $firstStreet;
}
