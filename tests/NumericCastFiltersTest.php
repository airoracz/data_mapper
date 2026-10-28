<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\Filters\ToFloatFilter;
use Pocta\DataMapper\Attributes\Filters\ToIntFilter;

class NumericCastFiltersTest extends TestCase
{
    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function toIntProvider(): array
    {
        return [
            'null' => [null, null],
            'int' => [5, 5],
            'float truncated' => [5.9, 5],
            'numeric string' => ['42', 42],
            'bool' => [true, 1],
            'space thousands' => ['1 000', 1000],
            'comma decimal' => ['12,7', 12],
            'trimmed' => ['  7  ', 7],
            'empty string kept' => ['', ''],
            'whitespace kept' => ['   ', '   '],
            'non-numeric kept' => ['abc', 'abc'],
            'array kept' => [['1'], ['1']],
            'nbsp thousands' => ["1\u{00A0}000", 1000],
            'nbsp thousands with decimal' => ["12\u{00A0}345,6", 12345],
        ];
    }

    #[DataProvider('toIntProvider')]
    public function testToInt(mixed $input, mixed $expected): void
    {
        $this->assertSame($expected, (new ToIntFilter())->apply($input));
    }

    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function toFloatProvider(): array
    {
        return [
            'null' => [null, null],
            'int' => [5, 5.0],
            'numeric string' => ['4.5', 4.5],
            'space thousands' => ['1 000,5', 1000.5],
            'comma decimal' => ['12,5', 12.5],
            'empty string kept' => ['', ''],
            'non-numeric kept' => ['abc', 'abc'],
            'nbsp thousands' => ["1\u{00A0}000,5", 1000.5],
        ];
    }

    #[DataProvider('toFloatProvider')]
    public function testToFloat(mixed $input, mixed $expected): void
    {
        $this->assertSame($expected, (new ToFloatFilter())->apply($input));
    }
}
