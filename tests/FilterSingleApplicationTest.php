<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\Filters\AddIntervalFilter;
use Pocta\DataMapper\Attributes\Filters\Base64DecodeFilter;
use Pocta\DataMapper\Attributes\Filters\Base64EncodeFilter;
use Pocta\DataMapper\Attributes\Filters\HashFilter;
use Pocta\DataMapper\Attributes\Filters\HtmlEntitiesEncodeFilter;
use Pocta\DataMapper\Attributes\Filters\PriceRoundFilter;
use Pocta\DataMapper\Attributes\Filters\SliceArrayFilter;
use Pocta\DataMapper\Attributes\Filters\StringToUpperFilter;
use Pocta\DataMapper\Attributes\Filters\StringTrimFilter;
use Pocta\DataMapper\Attributes\Filters\SubstringFilter;
use Pocta\DataMapper\Attributes\Filters\ToIntFilter;
use Pocta\DataMapper\Attributes\Filters\UrlEncodeFilter;
use Pocta\DataMapper\Mapper;

/**
 * During denormalization every filter must take effect exactly once: a filter that changed the raw
 * input value must not run again on the typed value (non-idempotent filters would apply twice).
 */
class FilterSingleApplicationTest extends TestCase
{
    /**
     * @template T of object
     * @param array<string, mixed> $data
     * @param class-string<T> $class
     * @return T
     */
    private function map(array $data, string $class): object
    {
        return (new Mapper())->fromArray($data, $class);
    }

    public function testNonIdempotentStringFiltersApplyOnce(): void
    {
        $dto = $this->map([
            'decoded' => base64_encode('hi'),
            'encoded' => 'hi',
            'html' => 'a&b',
            'url' => 'a b',
            'sub' => 'ABCDEF',
        ], FilterOnceStrings::class);

        $this->assertInstanceOf(FilterOnceStrings::class, $dto);
        $this->assertSame('hi', $dto->decoded);
        $this->assertSame(base64_encode('hi'), $dto->encoded);
        $this->assertSame('a&amp;b', $dto->html);
        $this->assertSame('a%20b', $dto->url);
        $this->assertSame('CDEF', $dto->sub);
    }

    public function testHashFilterHashesOnce(): void
    {
        $dto = $this->map(['password' => 'secret'], FilterOnceHash::class);

        $this->assertInstanceOf(FilterOnceHash::class, $dto);
        $this->assertTrue(password_verify('secret', $dto->password));
    }

    public function testPriceRoundAppliesOnce(): void
    {
        $dto = $this->map(['price' => -3.7], FilterOncePrice::class);

        $this->assertInstanceOf(FilterOncePrice::class, $dto);
        $this->assertSame((new PriceRoundFilter())->apply(-3.7), $dto->price);
    }

    public function testSliceArrayAppliesOnce(): void
    {
        $dto = $this->map(['items' => ['a', 'b', 'c', 'd']], FilterOnceSlice::class);

        $this->assertInstanceOf(FilterOnceSlice::class, $dto);
        $this->assertSame([1 => 'b', 2 => 'c', 3 => 'd'], $dto->items);
    }

    public function testConstructorParameterFiltersApplyOnce(): void
    {
        $dto = $this->map(['sub' => 'ABCDEF'], FilterOnceCtor::class);

        $this->assertInstanceOf(FilterOnceCtor::class, $dto);
        $this->assertSame('CDEF', $dto->sub);
    }

    // --- Unchanged behaviour ---

    public function testFilterActingOnlyOnTypedValueStillApplies(): void
    {
        $dto = $this->map(['due' => '2026-01-01'], FilterOnceTyped::class);

        $this->assertInstanceOf(FilterOnceTyped::class, $dto);
        $this->assertSame('2026-01-02', $dto->due?->format('Y-m-d'));
    }

    public function testIdempotentFiltersAreUnchanged(): void
    {
        $dto = $this->map(['name' => '  john ', 'qty' => '1 000'], FilterOnceIdempotent::class);

        $this->assertInstanceOf(FilterOnceIdempotent::class, $dto);
        $this->assertSame('JOHN', $dto->name);
        $this->assertSame(1000, $dto->qty);
    }

    public function testNormalizationAppliesFiltersOnce(): void
    {
        $dto = new FilterOnceStrings();
        $dto->encoded = 'hi';

        $this->assertSame(base64_encode('hi'), (new Mapper())->toArray($dto)['encoded']);
    }
}

class FilterOnceStrings
{
    #[Base64DecodeFilter]
    public string $decoded = '';

    #[Base64EncodeFilter]
    public string $encoded = '';

    #[HtmlEntitiesEncodeFilter]
    public string $html = '';

    #[UrlEncodeFilter]
    public string $url = '';

    #[SubstringFilter(2)]
    public string $sub = '';
}

class FilterOnceHash
{
    #[HashFilter]
    public string $password = '';
}

class FilterOncePrice
{
    #[PriceRoundFilter]
    public float $price = 0.0;
}

class FilterOnceSlice
{
    /** @var array<int, string> */
    #[SliceArrayFilter(1)]
    public array $items = [];
}

class FilterOnceCtor
{
    public function __construct(#[SubstringFilter(2)] public string $sub = '')
    {
    }
}

class FilterOnceTyped
{
    #[AddIntervalFilter('P1D')]
    public ?DateTimeImmutable $due = null;
}

class FilterOnceIdempotent
{
    #[StringTrimFilter]
    #[StringToUpperFilter]
    public string $name = '';

    #[ToIntFilter]
    public int $qty = 0;
}
