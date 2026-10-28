<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\Filters\StringTrimFilter;
use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Exceptions\ValidationException;
use Pocta\DataMapper\Mapper;

/**
 * merge() applies the same per-field rules as fromArray() (keys, paths, filters, types, errors),
 * and is atomic: on any error the target object stays unchanged.
 */
class MergeConsistencyTest extends TestCase
{
    public function testAllTypeErrorsAreCollected(): void
    {
        try {
            (new Mapper())->merge(['age' => 'abc', 'score' => 'xyz'], new MergeTarget());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['age', 'score'], array_keys($e->getErrors()));
        }
    }

    public function testObjectIsUnchangedWhenAnyFieldFails(): void
    {
        $target = new MergeTarget();

        try {
            (new Mapper())->merge(['name' => 'Changed', 'age' => 'abc'], $target);
        } catch (ValidationException) {
        }

        $this->assertSame('original', $target->name);
        $this->assertSame(1, $target->age);
    }

    public function testNullForNonNullablePropertyIsAValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Field 'age' does not accept null values");

        (new Mapper())->merge(['age' => null], new MergeTarget());
    }

    public function testFiltersAreApplied(): void
    {
        $target = (new Mapper())->merge(['name' => '  Jane  '], new MergeTarget());

        $this->assertSame('Jane', $target->name);
    }

    public function testPropertyPathIsSupported(): void
    {
        $target = (new Mapper())->merge(['address' => ['city' => 'Brno']], new MergeTarget());

        $this->assertSame('Brno', $target->city);
    }

    public function testMissingKeysLeavePropertiesUntouched(): void
    {
        $target = (new Mapper())->merge(['age' => 5], new MergeTarget());

        $this->assertSame('original', $target->name);
        $this->assertSame('Prague', $target->city);
        $this->assertSame(5, $target->age);
    }

    public function testNestedObjectIsDenormalized(): void
    {
        $target = (new Mapper())->merge(['nested' => ['name' => 'inner']], new MergeTarget());

        $this->assertSame('inner', $target->nested?->name);
    }
}

class MergeTarget
{
    #[StringTrimFilter]
    public string $name = 'original';

    public int $age = 1;

    public float $score = 0.0;

    #[MapProperty(path: 'address.city')]
    public string $city = 'Prague';

    public ?MergeNested $nested = null;
}

class MergeNested
{
    public string $name = '';
}
