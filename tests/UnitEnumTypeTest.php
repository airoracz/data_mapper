<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Types\UnitEnumType;

class UnitEnumTypeTest extends TestCase
{
    public function testResolvesCasesByName(): void
    {
        $type = new UnitEnumType(UnitEnumTypeColor::class);

        $this->assertSame(UnitEnumTypeColor::Red, $type->denormalize('Red', 'c', false));
        $this->assertSame(UnitEnumTypeColor::Blue, $type->denormalize('Blue', 'c', false));
        $this->assertSame(UnitEnumTypeColor::Red, $type->denormalize(UnitEnumTypeColor::Red, 'c', false));
        $this->assertNull($type->denormalize(null, 'c', true));
    }

    public function testCaseNameIsCaseSensitive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new UnitEnumType(UnitEnumTypeColor::class))->denormalize('red', 'c', false);
    }

    public function testUnknownCaseMessageListsValidCases(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid value for field 'color'. Expected one of: Red, Green, Blue");

        (new UnitEnumType(UnitEnumTypeColor::class))->denormalize('Pink', 'color', false);
    }

    public function testIntegerValueIsMatchedAsName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new UnitEnumType(UnitEnumTypeColor::class))->denormalize(1, 'c', false);
    }
}

enum UnitEnumTypeColor
{
    case Red;
    case Green;
    case Blue;
}
