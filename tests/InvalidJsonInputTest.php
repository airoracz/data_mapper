<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Exceptions\InvalidInputException;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\MapperOptions;

class InvalidJsonInputTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function nonObjectJsonProvider(): array
    {
        return [
            'string' => ['"str"'],
            'int' => ['1'],
            'bool' => ['true'],
            'null' => ['null'],
            'list' => ['[1, 2]'],
            'empty list' => ['[]'],
            'list of objects' => ['[{"name": "a"}]'],
        ];
    }

    #[DataProvider('nonObjectJsonProvider')]
    public function testFromJsonRejectsNonObjectJson(string $json): void
    {
        $this->expectException(InvalidInputException::class);

        (new Mapper())->fromJson($json, InvalidJsonInputDto::class);
    }

    public function testInvalidInputExceptionIsInvalidArgumentExceptionForBackwardCompatibility(): void
    {
        $this->assertInstanceOf(InvalidArgumentException::class, new InvalidInputException('x'));
    }

    public function testFromJsonAcceptsEmptyObject(): void
    {
        $dto = (new Mapper())->fromJson(" \n {}", InvalidJsonInputDto::class);

        $this->assertSame('default', $dto->name);
    }

    public function testFromArrayRejectsList(): void
    {
        $this->expectException(InvalidInputException::class);

        (new Mapper())->fromArray([1, 2], InvalidJsonInputDto::class);
    }

    public function testFromArrayAcceptsEmptyArray(): void
    {
        $dto = (new Mapper())->fromArray([], InvalidJsonInputDto::class);

        $this->assertSame('default', $dto->name);
    }

    public function testJsonDepthDefaultsTo512(): void
    {
        $this->assertSame(512, (new MapperOptions())->jsonDepth);
    }

    public function testWithPreservesJsonDepth(): void
    {
        $this->assertSame(16, (new MapperOptions(jsonDepth: 16))->with(strictMode: true)->jsonDepth);
    }

    public function testInvalidJsonDepthIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MapperOptions(jsonDepth: 0);
    }

    public function testFromJsonHonoursJsonDepth(): void
    {
        $mapper = new Mapper(new MapperOptions(jsonDepth: 2));

        $this->expectException(JsonException::class);

        $mapper->fromJson('{"name": "a", "tags": {"x": {"y": 1}}}', InvalidJsonInputDto::class);
    }

    public function testFromJsonCollectionHonoursJsonDepth(): void
    {
        $mapper = new Mapper(new MapperOptions(jsonDepth: 2));

        $this->expectException(JsonException::class);

        $mapper->fromJsonCollection('[{"tags": {"x": 1}}]', InvalidJsonInputDto::class);
    }

    public function testFromJsonCollectionRejectsObject(): void
    {
        $this->expectException(InvalidInputException::class);

        (new Mapper())->fromJsonCollection('{"a": {"name": "x"}}', InvalidJsonInputDto::class);
    }

    public function testFromJsonCollectionRejectsNonObjectElement(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('[1]');

        (new Mapper())->fromJsonCollection('[{"name": "a"}, 2]', InvalidJsonInputDto::class);
    }

    public function testFromJsonCollectionRejectsListElement(): void
    {
        $this->expectException(InvalidInputException::class);

        (new Mapper())->fromJsonCollection('[["a"]]', InvalidJsonInputDto::class);
    }
}

class InvalidJsonInputDto
{
    public string $name = 'default';

    /** @var array<string, mixed> */
    public array $tags = [];
}
