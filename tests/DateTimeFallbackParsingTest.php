<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Types\DateTimeType;

/**
 * Characterization of DateTimeType parsing without an explicit format (fallback formats).
 */
class DateTimeFallbackParsingTest extends TestCase
{
    /**
     * @return array<string, array{string|int, string}>
     */
    public static function acceptedProvider(): array
    {
        return [
            'iso with microseconds' => ['2026-01-02T10:00:00.123456+01:00', '2026-01-02T10:00:00.123456+01:00'],
            'iso without microseconds' => ['2026-01-02T10:00:00+00:00', '2026-01-02T10:00:00.000000+00:00'],
            'mysql datetime' => ['2026-01-02 10:20:30', '2026-01-02T10:20:30.000000+00:00'],
            'date only' => ['2026-01-02', '2026-01-02'],
            'timestamp string' => ['1700000000', '2023-11-14T22:13:20.000000+00:00'],
            'timestamp int' => [1700000000, '2023-11-14T22:13:20.000000+00:00'],
        ];
    }

    #[DataProvider('acceptedProvider')]
    public function testAcceptedValues(string|int $input, string $expected): void
    {
        $result = (new DateTimeType())->denormalize($input, 'd', false);

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $format = strlen($expected) === 10 ? 'Y-m-d' : 'Y-m-d\TH:i:s.uP';
        $this->assertSame($expected, $result->format($format));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedProvider(): array
    {
        return [
            'corrected date' => ['2025-02-30'],
            'zero date' => ['0000-00-00'],
            'garbage' => ['not a date'],
            'iso with Z' => ['2026-01-02T10:00:00Z'],
            'czech format' => ['02.01.2026'],
            'empty' => [''],
            'time only' => ['10:00:00'],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function testRejectedValues(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DateTimeType())->denormalize($input, 'd', false);
    }

    public function testTimezoneIsAppliedOnEveryCall(): void
    {
        $type = new DateTimeType(timezone: 'Europe/Prague');

        $first = $type->denormalize('2026-01-02 10:00:00', 'd', false);
        $second = $type->denormalize('2026-07-02 10:00:00', 'd', false);

        $this->assertInstanceOf(DateTimeInterface::class, $first);
        $this->assertInstanceOf(DateTimeInterface::class, $second);
        $this->assertSame('+01:00', $first->format('P'));
        $this->assertSame('+02:00', $second->format('P'));
    }

    public function testInvalidTimezoneThrowsOnEveryCall(): void
    {
        $type = new DateTimeType(timezone: 'Mars/Olympus');

        foreach ([1, 2] as $attempt) {
            try {
                $type->denormalize('2026-01-02', 'd', false);
                $this->fail("Attempt {$attempt}: expected InvalidArgumentException");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid timezone', $e->getMessage());
            }
        }
    }
}
