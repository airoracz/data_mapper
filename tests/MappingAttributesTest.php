<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\ArrayElementType;
use Pocta\DataMapper\Attributes\MapDateTimeProperty;
use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Attributes\MappingAttributes;
use Pocta\DataMapper\Attributes\PropertyType;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Rules for reading #[MapProperty] / #[MapDateTimeProperty]; MapDateTimeProperty takes precedence.
 */
class MappingAttributesTest extends TestCase
{
    private function of(string $property): MappingAttributes
    {
        return MappingAttributes::of(new ReflectionProperty(MappingAttributesDto::class, $property));
    }

    public function testPlainPropertyUsesNameAndReflectionType(): void
    {
        $attrs = $this->of('plain');

        $this->assertSame('plain', $attrs->jsonKey());
        $this->assertSame('int', $attrs->typeName());
        $this->assertNull($attrs->path());
        $this->assertNull($attrs->arrayOf());
        $this->assertNull($attrs->classType());
        $this->assertNull($attrs->format());
        $this->assertNull($attrs->timezone());
        $this->assertNull($attrs->outputFormat());
    }

    public function testUntypedPropertyDefaultsToString(): void
    {
        $this->assertSame('string', $this->of('untyped')->typeName());
    }

    public function testMapPropertySettings(): void
    {
        $attrs = $this->of('renamed');

        $this->assertSame('renamed_key', $attrs->jsonKey());
        $this->assertSame('array', $attrs->typeName());
        $this->assertSame('int', $attrs->arrayOf());
        $this->assertSame(MappingAttributesDto::class, $attrs->classType());
    }

    public function testMapPropertyPath(): void
    {
        $attrs = $this->of('nested');

        $this->assertSame('nested', $attrs->jsonKey());
        $this->assertSame('a.b', $attrs->path());
    }

    public function testDateTimeAttributeSettings(): void
    {
        $attrs = $this->of('date');

        $this->assertSame('created', $attrs->jsonKey());
        $this->assertSame(DateTimeImmutable::class, $attrs->typeName());
        $this->assertSame('Y-m-d', $attrs->format());
        $this->assertSame('Europe/Prague', $attrs->timezone());
        $this->assertSame('d.m.Y', $attrs->outputFormat());
    }

    public function testDateTimeAttributeTakesPrecedenceEvenWithNullValues(): void
    {
        $attrs = $this->of('both');

        // name, path and arrayOf come from MapDateTimeProperty even when it leaves them null
        $this->assertSame('both', $attrs->jsonKey());
        $this->assertNull($attrs->path());
        $this->assertNull($attrs->arrayOf());
        // type falls through to MapProperty when MapDateTimeProperty has none
        $this->assertSame('string', $attrs->typeName());
        // classType comes from MapProperty only
        $this->assertSame(MappingAttributesDto::class, $attrs->classType());
    }

    public function testPropertyTypeArrayOfIsConvertedToString(): void
    {
        $this->assertSame('string', $this->of('legacyArrayOf')->arrayOf());
    }

    public function testConstructorParameter(): void
    {
        $attrs = MappingAttributes::of(new ReflectionParameter([MappingAttributesDto::class, '__construct'], 'promoted'));

        $this->assertSame('promoted_key', $attrs->jsonKey());
        $this->assertSame('float', $attrs->typeName());
    }
}

class MappingAttributesDto
{
    public int $plain = 0;

    /** @phpstan-ignore missingType.property */
    public $untyped;

    /** @var array<int, int> */
    #[MapProperty(name: 'renamed_key', type: PropertyType::Array, arrayOf: ArrayElementType::Int, classType: MappingAttributesDto::class)]
    public array $renamed = [];

    #[MapProperty(path: 'a.b')]
    public ?string $nested = null;

    #[MapDateTimeProperty(name: 'created', format: 'Y-m-d', timezone: 'Europe/Prague', outputFormat: 'd.m.Y')]
    public ?DateTimeImmutable $date = null;

    #[MapDateTimeProperty]
    #[MapProperty(name: 'ignored', path: null, type: PropertyType::String, arrayOf: ArrayElementType::Int, classType: MappingAttributesDto::class)]
    public mixed $both = null;

    /** @var array<int, string> */
    #[MapProperty(arrayOf: PropertyType::String)]
    public array $legacyArrayOf = [];

    public function __construct(
        #[MapProperty(name: 'promoted_key')]
        public float $promoted = 0.0,
    ) {
    }
}
