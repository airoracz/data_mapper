<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Attributes\PropertyType;
use Pocta\DataMapper\Exceptions\ValidationException;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\MapperOptions;

/**
 * Options must apply to nested objects and arrays of objects, not only to the root.
 */
class NestedOptionsPropagationTest extends TestCase
{
    private function createMapper(bool $strictMode = false): Mapper
    {
        return new Mapper(new MapperOptions(
            strictMode: $strictMode,
            publicPropertiesOnly: true,
            symmetricPropertyPaths: true,
        ));
    }

    private function createOuter(): NestedOuterDto
    {
        $outer = new NestedOuterDto();
        $outer->inner = new NestedInnerDto();
        $outer->items = [new NestedInnerDto()];

        return $outer;
    }

    public function testNestedObjectOutputSkipsNonPublicProperties(): void
    {
        $result = $this->createMapper()->toArray($this->createOuter());

        $this->assertIsArray($result['inner']);
        $this->assertArrayNotHasKey('secret', $result['inner']);
    }

    public function testNestedArrayOutputSkipsNonPublicProperties(): void
    {
        $result = $this->createMapper()->toArray($this->createOuter());

        $this->assertIsArray($result['items']);
        $this->assertIsArray($result['items'][0]);
        $this->assertArrayNotHasKey('secret', $result['items'][0]);
    }

    public function testNestedObjectInputIgnoresNonPublicProperties(): void
    {
        $outer = $this->createMapper()->fromArray(['inner' => ['secret' => 'HACKED']], NestedOuterDto::class);

        $this->assertSame('hash', $outer->inner->getSecret());
    }

    public function testNestedArrayInputIgnoresNonPublicProperties(): void
    {
        $outer = $this->createMapper()->fromArray(
            ['inner' => [], 'items' => [['secret' => 'HACKED']]],
            NestedOuterDto::class
        );

        $this->assertSame('hash', $outer->items[0]->getSecret());
    }

    public function testStrictModeRejectsNonPublicKeyInNestedObject(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Unknown key 'secret' at path 'inner.secret'");

        $this->createMapper(strictMode: true)->fromArray(['inner' => ['secret' => 'x']], NestedOuterDto::class);
    }

    public function testSkipNullValuesAppliesToNestedObject(): void
    {
        $result = (new Mapper(new MapperOptions(skipNullValues: true)))->toArray($this->createNullableOuter());

        $this->assertSame(['value' => 'v'], $result['inner']);
    }

    public function testSkipNullValuesAppliesToArrayOfObjects(): void
    {
        $result = (new Mapper(new MapperOptions(skipNullValues: true)))->toArray($this->createNullableOuter());

        $this->assertSame([['value' => 'v']], $result['items']);
    }

    public function testNullsInArrayOfObjectsAreKeptByDefault(): void
    {
        $result = (new Mapper())->toArray($this->createNullableOuter());

        $this->assertSame([['value' => 'v', 'note' => null]], $result['items']);
        $this->assertSame(['value' => 'v', 'note' => null], $result['inner']);
    }

    private function createNullableOuter(): NestedNullableOuterDto
    {
        $outer = new NestedNullableOuterDto();
        $outer->inner = new NestedNullableInnerDto();
        $outer->items = [new NestedNullableInnerDto()];

        return $outer;
    }

    public function testThrowOnMissingDataFalseAppliesToNestedObjects(): void
    {
        $mapper = new Mapper(new MapperOptions(throwOnMissingData: false));

        $holder = $mapper->fromArray(['leaf' => ['other' => 'x']], NestedLeafHolder::class);

        $this->assertInstanceOf(NestedLeaf::class, $holder->leaf);
    }

    public function testMissingNestedDataIsStillRejectedByDefault(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("leaf.required");

        (new Mapper())->fromArray(['leaf' => ['other' => 'x']], NestedLeafHolder::class);
    }

    public function testStrictModeEnabledAtRuntimeAppliesToNestedObjects(): void
    {
        $mapper = new Mapper();
        $mapper->setStrictMode(true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Unknown key 'typo' at path 'leaf.typo'");

        $mapper->fromArray(['leaf' => ['required' => 'r', 'typo' => 'x']], NestedLeafHolder::class);
    }

    public function testStrictModeDisabledAtRuntimeAppliesToNestedObjects(): void
    {
        $mapper = new Mapper(MapperOptions::withStrictMode());
        $mapper->fromArray(['leaf' => ['required' => 'r']], NestedLeafHolder::class);
        $mapper->setStrictMode(false);

        $holder = $mapper->fromArray(['leaf' => ['required' => 'r', 'typo' => 'x']], NestedLeafHolder::class);

        $this->assertSame('r', $holder->leaf->required);
    }

    public function testThrowOnMissingDataChangedAtRuntimeAppliesToNestedObjects(): void
    {
        $mapper = new Mapper();
        $mapper->setThrowOnMissingData(false);

        $holder = $mapper->fromArray(['leaf' => ['other' => 'x']], NestedLeafHolder::class);

        $this->assertInstanceOf(NestedLeaf::class, $holder->leaf);
    }

    public function testNestedObjectOutputWritesPropertyPath(): void
    {
        $result = $this->createMapper()->toArray($this->createOuter());

        $this->assertIsArray($result['inner']);
        $this->assertSame(['city' => 'Prague'], $result['inner']['address'] ?? null);
    }
}

class NestedInnerDto
{
    public string $name = 'inner';

    private string $secret = 'hash';

    #[MapProperty(path: 'address.city')]
    public string $city = 'Prague';

    public function getSecret(): string
    {
        return $this->secret;
    }
}

class NestedOuterDto
{
    public NestedInnerDto $inner;

    /** @var NestedInnerDto[] */
    #[MapProperty(type: PropertyType::Array, arrayOf: NestedInnerDto::class)]
    public array $items = [];
}

class NestedNullableInnerDto
{
    public string $value = 'v';

    public ?string $note = null;
}

class NestedNullableOuterDto
{
    public NestedNullableInnerDto $inner;

    /** @var NestedNullableInnerDto[] */
    #[MapProperty(arrayOf: NestedNullableInnerDto::class)]
    public array $items = [];
}

class NestedLeaf
{
    public string $required;

    public string $other = '';
}

class NestedLeafHolder
{
    public NestedLeaf $leaf;
}
