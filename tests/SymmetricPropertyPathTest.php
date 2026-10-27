<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\MapDateTimeProperty;
use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\MapperOptions;
use Pocta\DataMapper\PropertyPathResolver;

class SymmetricPropertyPathTest extends TestCase
{
    private function createMapper(): Mapper
    {
        return new Mapper(new MapperOptions(symmetricPropertyPaths: true));
    }

    public function testOptionIsDisabledByDefault(): void
    {
        $this->assertFalse((new MapperOptions())->symmetricPropertyPaths);
    }

    public function testWithPreservesOption(): void
    {
        $options = (new MapperOptions(symmetricPropertyPaths: true))->with(strictMode: true);

        $this->assertTrue($options->symmetricPropertyPaths);
    }

    public function testDefaultModeKeepsFlatOutput(): void
    {
        $result = (new Mapper())->toArray($this->createAddressDto());

        $this->assertSame(['name' => 'Office', 'city' => 'Prague', 'zip' => '11000'], $result);
    }

    public function testNormalizeWritesValuesBackToPath(): void
    {
        $result = $this->createMapper()->toArray($this->createAddressDto());

        $this->assertSame(
            ['name' => 'Office', 'address' => ['city' => 'Prague', 'zip' => '11000']],
            $result
        );
    }

    public function testNormalizeWritesIndexedPath(): void
    {
        $dto = new SymmetricIndexedDto();
        $dto->firstStreet = 'Main';

        $result = $this->createMapper()->toArray($dto);

        $this->assertSame(['addresses' => [['street' => 'Main']]], $result);
    }

    public function testNormalizeWritesDateTimePropertyPath(): void
    {
        $dto = new SymmetricDateTimeDto();
        $dto->createdAt = new DateTimeImmutable('2026-01-02 03:04:05');

        $result = $this->createMapper()->toArray($dto);

        $this->assertSame(['meta' => ['created' => '2026-01-02']], $result);
    }

    public function testRoundTripPreservesShape(): void
    {
        $mapper = $this->createMapper();
        $input = ['name' => 'Office', 'address' => ['city' => 'Prague', 'zip' => '11000']];

        $output = $mapper->toArray($mapper->fromArray($input, SymmetricAddressDto::class));

        $this->assertSame($input, $output);
    }

    public function testResolverSetCreatesNestedStructure(): void
    {
        $data = ['address' => ['city' => 'Prague']];

        PropertyPathResolver::set($data, 'address.zip', '11000');
        PropertyPathResolver::set($data, 'tags[1]', 'b');

        $this->assertSame(['address' => ['city' => 'Prague', 'zip' => '11000'], 'tags' => [null, 'b']], $data);
    }

    public function testResolverSetRejectsDescendingIntoScalar(): void
    {
        $data = ['meta' => 'x'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'meta'");

        PropertyPathResolver::set($data, 'meta.source', 's');
    }

    public function testResolverSetKeepsListWhenIndexesWrittenOutOfOrder(): void
    {
        $data = [];

        PropertyPathResolver::set($data, 'items[1].name', 'b');
        PropertyPathResolver::set($data, 'items[0].name', 'a');

        $this->assertSame(['items' => [['name' => 'a'], ['name' => 'b']]], $data);
    }

    public function testIndexedPathIsEncodedAsJsonList(): void
    {
        $json = $this->createMapper()->toJson(new SymmetricSecondItemDto());

        $this->assertSame('{"items":[null,{"name":"second"}]}', $json);
    }

    public function testIndexedPathRoundTrips(): void
    {
        $mapper = $this->createMapper();

        $dto = $mapper->fromJson($mapper->toJson(new SymmetricSecondItemDto()), SymmetricSecondItemDto::class);

        $this->assertSame('second', $dto->name);
    }

    public function testPlainPropertyCollidingWithPathRootThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('meta');

        $this->createMapper()->toArray(new SymmetricPlainThenPathDto());
    }

    public function testPathCollidingWithEarlierPlainPropertyThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('meta');

        $this->createMapper()->toArray(new SymmetricPathThenPlainDto());
    }

    public function testTwoPropertiesWithSamePathThrow(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('address.city');

        $this->createMapper()->toArray(new SymmetricDuplicatePathDto());
    }

    public function testToCsvUsesFlatColumnsWithSymmetricPaths(): void
    {
        $csv = $this->createMapper()->toCsv([$this->createAddressDto()]);

        $this->assertSame("name,city,zip\nOffice,Prague,11000\n", $csv);
    }

    public function testResolverSetRejectsPathStartingWithIndex(): void
    {
        $data = [];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with a property name');

        PropertyPathResolver::set($data, '[0].name', 'x');
    }

    private function createAddressDto(): SymmetricAddressDto
    {
        $dto = new SymmetricAddressDto();
        $dto->name = 'Office';
        $dto->city = 'Prague';
        $dto->zip = '11000';

        return $dto;
    }
}

class SymmetricAddressDto
{
    public string $name;

    #[MapProperty(path: 'address.city')]
    public string $city;

    #[MapProperty(path: 'address.zip')]
    public string $zip;
}

class SymmetricIndexedDto
{
    #[MapProperty(path: 'addresses[0].street')]
    public string $firstStreet;
}

class SymmetricDateTimeDto
{
    #[MapDateTimeProperty(path: 'meta.created', outputFormat: 'Y-m-d')]
    public DateTimeImmutable $createdAt;
}

class SymmetricSecondItemDto
{
    #[MapProperty(path: 'items[1].name')]
    public string $name = 'second';
}

class SymmetricPlainThenPathDto
{
    public string $meta = 'x';

    #[MapProperty(path: 'meta.source')]
    public string $source = 's';
}

class SymmetricPathThenPlainDto
{
    #[MapProperty(path: 'meta.source')]
    public string $source = 's';

    public string $meta = 'x';
}

class SymmetricDuplicatePathDto
{
    #[MapProperty(path: 'address.city')]
    public string $city = 'Prague';

    #[MapProperty(path: 'address.city')]
    public string $town = 'Brno';
}
