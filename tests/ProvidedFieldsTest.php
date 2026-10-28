<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Exceptions\ValidationException;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\MapperOptions;
use Pocta\DataMapper\ProvidedFields;
use Pocta\DataMapper\ProvidedFieldsInterface;
use Pocta\DataMapper\Validation as Assert;

/**
 * PATCH semantics: distinguish "not sent" from "sent as null" via the provided-fields tracking.
 */
class ProvidedFieldsTest extends TestCase
{
    public function testSentFieldsAreProvidedIncludingNull(): void
    {
        $dto = (new Mapper())->fromArray(['name' => 'Jan', 'note' => null], PatchDto::class);

        $this->assertTrue($dto->isProvided('name'));
        $this->assertTrue($dto->isProvided('note'));
        $this->assertNull($dto->note);
        $this->assertFalse($dto->isProvided('email'));
        $this->assertSame(['name', 'note'], $dto->getProvidedFields());
    }

    public function testRenamedKeyIsTrackedByPropertyName(): void
    {
        $dto = (new Mapper())->fromArray(['birth_date' => '2000-01-01'], PatchDto::class);

        $this->assertTrue($dto->isProvided('birthDate'));
        $this->assertSame(['birthDate'], $dto->getProvidedFields());
    }

    public function testPathIsProvidedWhenItExists(): void
    {
        $mapper = new Mapper();

        $this->assertTrue($mapper->fromArray(['address' => ['city' => null]], PatchDto::class)->isProvided('city'));
        $this->assertFalse($mapper->fromArray(['address' => []], PatchDto::class)->isProvided('city'));
    }

    public function testConstructorParametersAreTracked(): void
    {
        $dto = (new Mapper())->fromArray(['id' => 5], PatchCtorDto::class);

        $this->assertTrue($dto->isProvided('id'));
        $this->assertFalse($dto->isProvided('label'));
    }

    public function testNestedObjectsAndArrayElementsTrackTheirOwnFields(): void
    {
        $dto = (new Mapper())->fromArray(
            ['inner' => ['note' => null], 'items' => [['name' => 'a'], ['note' => 'b']]],
            PatchParentDto::class
        );

        $this->assertSame(['inner', 'items'], $dto->getProvidedFields());
        $this->assertSame(['note'], $dto->inner?->getProvidedFields());
        $this->assertSame(['name'], $dto->items[0]->getProvidedFields());
        $this->assertSame(['note'], $dto->items[1]->getProvidedFields());
    }

    public function testTrackingIsNotSerializedNorHydratable(): void
    {
        $mapper = new Mapper();

        $dto = $mapper->fromArray(['name' => 'Jan', 'providedFields' => ['email']], PatchDto::class);

        $this->assertFalse($dto->isProvided('email'));
        $this->assertArrayNotHasKey('providedFields', $mapper->toArray($dto));
    }

    public function testStrictModeRejectsTrackingKey(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Unknown key 'providedFields'");

        (new Mapper(MapperOptions::withStrictMode()))->fromArray(['providedFields' => []], PatchDto::class);
    }

    public function testValidationIgnoresTracking(): void
    {
        $dto = (new Mapper(MapperOptions::withAutoValidation()))->fromArray(['name' => 'Jan'], PatchDto::class);

        $this->assertTrue($dto->isProvided('name'));
    }

    public function testMergeAddsProvidedFields(): void
    {
        $mapper = new Mapper();
        $dto = $mapper->fromArray(['name' => 'Jan'], PatchDto::class);

        $mapper->merge(['note' => null], $dto);

        $this->assertSame(['name', 'note'], $dto->getProvidedFields());
    }

    public function testMissingNullablePropertyWithoutDefaultIsNullNotUninitialized(): void
    {
        $dto = (new Mapper())->fromArray([], PatchNoDefaultDto::class);

        $this->assertNull($dto->note);
        $this->assertFalse($dto->isProvided('note'));
    }

    public function testMissingNullableConstructorParameterIsNullAsBefore(): void
    {
        $dto = (new Mapper())->fromArray([], PatchNoDefaultCtorDto::class);

        $this->assertNull($dto->note);
    }

    public function testClassesWithoutInterfaceAreUnaffected(): void
    {
        $dto = (new Mapper())->fromArray(['name' => 'Jan'], PatchPlainDto::class);

        $this->assertSame('Jan', $dto->name);
    }
}

class PatchDto implements ProvidedFieldsInterface
{
    use ProvidedFields;

    #[Assert\Length(max: 50)]
    public ?string $name = null;

    public ?string $note = null;

    public ?string $email = null;

    #[MapProperty(name: 'birth_date')]
    public ?string $birthDate = null;

    #[MapProperty(path: 'address.city')]
    public ?string $city = null;
}

class PatchCtorDto implements ProvidedFieldsInterface
{
    use ProvidedFields;

    public function __construct(public ?int $id = null, public ?string $label = null)
    {
    }
}

class PatchParentDto implements ProvidedFieldsInterface
{
    use ProvidedFields;

    public ?PatchDto $inner = null;

    /** @var array<int, PatchDto> */
    #[MapProperty(arrayOf: PatchDto::class)]
    public array $items = [];
}

class PatchPlainDto
{
    public ?string $name = null;
}

class PatchNoDefaultDto implements ProvidedFieldsInterface
{
    use ProvidedFields;

    public ?string $note;
}

class PatchNoDefaultCtorDto
{
    public function __construct(public ?string $note)
    {
    }
}
