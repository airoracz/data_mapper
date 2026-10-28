<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\ArrayElementType;
use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Exceptions\ValidationException;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\MapperOptions;

class ParseFormattedNumbersTest extends TestCase
{
    private function createMapper(): Mapper
    {
        return new Mapper(new MapperOptions(parseFormattedNumbers: true));
    }

    public function testOptionIsDisabledByDefault(): void
    {
        $this->assertFalse((new MapperOptions())->parseFormattedNumbers);
    }

    public function testWithPreservesOption(): void
    {
        $this->assertTrue((new MapperOptions(parseFormattedNumbers: true))->with(strictMode: true)->parseFormattedNumbers);
    }

    // --- Default behaviour is unchanged ---

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedByDefaultProvider(): array
    {
        return [
            'int with space' => ['qty', '10 000'],
            'int with nbsp' => ['qty', "10\u{00A0}000"],
            'float with comma' => ['price', '12,5'],
            'float with space and comma' => ['price', '1 234,50'],
        ];
    }

    #[DataProvider('rejectedByDefaultProvider')]
    public function testFormattedNumbersAreRejectedByDefault(string $field, string $value): void
    {
        $this->expectException(ValidationException::class);

        (new Mapper())->fromArray([$field => $value], FormattedNumbersDto::class);
    }

    public function testPlainNumericStringsWorkWithAndWithoutOption(): void
    {
        foreach ([new Mapper(), $this->createMapper()] as $mapper) {
            $dto = $mapper->fromArray(['qty' => '10000', 'price' => '12.5'], FormattedNumbersDto::class);

            $this->assertSame(10000, $dto->qty);
            $this->assertSame(12.5, $dto->price);
        }
    }

    public function testExistingIntTruncationOfDotDecimalIsUnchanged(): void
    {
        $dto = $this->createMapper()->fromArray(['qty' => '12.5'], FormattedNumbersDto::class);

        $this->assertSame(12, $dto->qty);
    }

    // --- Option enabled: int ---

    /**
     * @return array<string, array{string, int}>
     */
    public static function intProvider(): array
    {
        return [
            'space thousands' => ['10 000', 10000],
            'nbsp thousands' => ["10\u{00A0}000", 10000],
            'narrow nbsp thousands' => ["10\u{202F}000", 10000],
            'multiple groups' => ['1 000 000', 1000000],
            'surrounding whitespace' => ['  42 ', 42],
            'negative' => ['-1 500', -1500],
            'comma with zero fraction' => ['12,0', 12],
        ];
    }

    #[DataProvider('intProvider')]
    public function testIntParsesFormattedNumbers(string $input, int $expected): void
    {
        $dto = $this->createMapper()->fromArray(['qty' => $input], FormattedNumbersDto::class);

        $this->assertSame($expected, $dto->qty);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIntProvider(): array
    {
        return [
            'comma with fraction (no silent truncation)' => ['12,5'],
            'dot and comma' => ['1.000,5'],
            'multiple commas' => ['1,2,3'],
            'not a number' => ['abc'],
            'empty' => [''],
            'only spaces' => ['   '],
        ];
    }

    #[DataProvider('invalidIntProvider')]
    public function testIntRejectsAmbiguousOrInvalidInput(string $input): void
    {
        $this->expectException(ValidationException::class);

        $this->createMapper()->fromArray(['qty' => $input], FormattedNumbersDto::class);
    }

    // --- Option enabled: float ---

    /**
     * @return array<string, array{string, float}>
     */
    public static function floatProvider(): array
    {
        return [
            'comma decimal' => ['12,5', 12.5],
            'space thousands and comma decimal' => ['1 234,50', 1234.5],
            'nbsp thousands and comma decimal' => ["1\u{00A0}234,50", 1234.5],
            'dot decimal' => ['12.5', 12.5],
            'space thousands and dot decimal' => ['1 234.5', 1234.5],
            'negative' => ['-1 000,25', -1000.25],
            'integer value' => ['10 000', 10000.0],
        ];
    }

    #[DataProvider('floatProvider')]
    public function testFloatParsesFormattedNumbers(string $input, float $expected): void
    {
        $dto = $this->createMapper()->fromArray(['price' => $input], FormattedNumbersDto::class);

        $this->assertSame($expected, $dto->price);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFloatProvider(): array
    {
        return [
            'dot and comma' => ['1,234.5'],
            'multiple commas' => ['1,2,3'],
            'not a number' => ['12,5 Kč'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidFloatProvider')]
    public function testFloatRejectsAmbiguousOrInvalidInput(string $input): void
    {
        $this->expectException(ValidationException::class);

        $this->createMapper()->fromArray(['price' => $input], FormattedNumbersDto::class);
    }

    // --- Nested and array elements ---

    public function testAppliesToArrayOfIntElements(): void
    {
        $dto = $this->createMapper()->fromArray(['quantities' => ['1 000', '2']], FormattedNumbersDto::class);

        $this->assertSame([1000, 2], $dto->quantities);
    }

    public function testAppliesToNestedObject(): void
    {
        $holder = $this->createMapper()->fromArray(['item' => ['price' => '1 234,50']], FormattedNumbersHolder::class);

        $this->assertSame(1234.5, $holder->item->price);
    }
}

class FormattedNumbersDto
{
    public int $qty = 0;

    public float $price = 0.0;

    /** @var array<int, int> */
    #[MapProperty(arrayOf: ArrayElementType::Int)]
    public array $quantities = [];
}

class FormattedNumbersHolder
{
    public FormattedNumbersDto $item;
}
