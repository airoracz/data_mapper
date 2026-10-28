<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Types;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Type handler for DateTime, DateTimeImmutable, and DateTimeInterface
 */
class DateTimeType implements TypeInterface
{
    private const DEFAULT_FORMAT = 'Y-m-d\TH:i:s.uP';
    private ?DateTimeZone $resolvedTimezone = null;

    private const FALLBACK_FORMATS = [
        'Y-m-d\TH:i:s.uP',      // ISO 8601 with microseconds
        'Y-m-d\TH:i:sP',        // ISO 8601 without microseconds
        'Y-m-d H:i:s',          // MySQL datetime
        'Y-m-d',                // Date only
        'U',                    // Unix timestamp
    ];

    /**
     * @param class-string<DateTimeInterface> $className
     * @param string|null $format Custom input format (for denormalization)
     * @param string|null $timezone Timezone name
     * @param string|null $outputFormat Custom output format (for normalization). If null, uses DEFAULT_FORMAT.
     */
    public function __construct(
        private readonly string $className = DateTimeImmutable::class,
        private readonly ?string $format = null,
        private readonly ?string $timezone = null,
        private readonly ?string $outputFormat = null
    ) {
        if (!in_array($this->className, [DateTime::class, DateTimeImmutable::class, DateTimeInterface::class], true)) {
            throw new InvalidArgumentException(
                "Class '{$this->className}' must be DateTime, DateTimeImmutable, or DateTimeInterface"
            );
        }
    }

    public function getName(): string
    {
        return $this->className;
    }

    public function getAliases(): array
    {
        return [$this->className];
    }

    public function denormalize(mixed $value, string $fieldName, bool $isNullable): mixed
    {
        if ($value === null) {
            if ($isNullable) {
                return null;
            }
            throw new InvalidArgumentException(
                "Field '{$fieldName}' does not accept null values"
            );
        }

        // If value is already a DateTimeInterface instance, return it
        if ($value instanceof DateTimeInterface) {
            return $this->convertToTargetClass($value);
        }

        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException(
                "Field '{$fieldName}' must be a string or integer (timestamp), got: " . get_debug_type($value)
            );
        }

        $timezone = $this->getTimezone();

        // Try custom format first
        if ($this->format !== null) {
            $dateTime = $this->tryParseWithFormat((string) $value, $this->format, $timezone, $fieldName);
            if ($dateTime !== null) {
                return $dateTime;
            }
        }

        // Try fallback formats
        $stringValue = (string) $value;
        foreach (self::FALLBACK_FORMATS as $format) {
            if (!self::canRoundTrip($format, $stringValue)) {
                continue;
            }
            $dateTime = $this->tryParseWithFormat($stringValue, $format, $timezone, $fieldName, false);
            if ($dateTime !== null) {
                return $dateTime;
            }
        }

        throw new InvalidArgumentException(
            "Field '{$fieldName}' has invalid datetime format. Value: '{$value}'"
            . ($this->format ? ", expected format: '{$this->format}'" : '')
        );
    }

    public function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (!$value instanceof DateTimeInterface) {
            throw new InvalidArgumentException(
                "Expected instance of DateTimeInterface, got: " . get_debug_type($value)
            );
        }

        return $value->format($this->outputFormat ?? self::DEFAULT_FORMAT);
    }

    /**
     * Cheap pre-check whether a fallback format's output could equal the value. A format whose
     * output always contains (or never contains) a character cannot pass the round-trip check
     * in tryParseWithFormat() for a value without (or with) it, so parsing it would return null anyway.
     */
    private static function canRoundTrip(string $format, string $value): bool
    {
        return match ($format) {
            'Y-m-d\TH:i:s.uP' => str_contains($value, 'T') && str_contains($value, '.'),
            'Y-m-d\TH:i:sP' => str_contains($value, 'T'),
            'Y-m-d H:i:s' => str_contains($value, ' '),
            'Y-m-d', 'U' => !str_contains($value, ':'),
            default => true,
        };
    }

    /**
     * Gets the configured timezone or default UTC.
     * DateTimeZone is immutable, so one instance is reused; an invalid timezone is not cached
     * and keeps throwing on every call.
     */
    private function getTimezone(): DateTimeZone
    {
        if ($this->resolvedTimezone !== null) {
            return $this->resolvedTimezone;
        }

        if ($this->timezone !== null) {
            try {
                return $this->resolvedTimezone = new DateTimeZone($this->timezone);
            } catch (\Exception $e) {
                throw new InvalidArgumentException(
                    "Invalid timezone: '{$this->timezone}'. " . $e->getMessage()
                );
            }
        }

        return $this->resolvedTimezone = new DateTimeZone('UTC');
    }

    /**
     * Tries to parse a datetime string with a specific format
     */
    private function tryParseWithFormat(
        string $value,
        string $format,
        DateTimeZone $timezone,
        string $fieldName,
        bool $throwOnError = false
    ): ?DateTimeInterface {
        // Use the concrete class for parsing, defaulting to DateTimeImmutable
        $className = $this->className === DateTimeInterface::class
            ? DateTimeImmutable::class
            : $this->className;

        $dateTime = $className::createFromFormat($format, $value, $timezone);

        if ($dateTime === false) {
            if ($throwOnError) {
                throw new InvalidArgumentException(
                    "Field '{$fieldName}' could not be parsed with format '{$format}'. Value: '{$value}'"
                );
            }
            return null;
        }

        // Round-trip check: reject dates that PHP silently "corrected"
        // e.g. 2025-02-30 → 2025-03-02, 0000-00-00 → -0001-11-30
        if ($dateTime->format($format) !== $value) {
            if ($throwOnError) {
                throw new InvalidArgumentException(
                    "Field '{$fieldName}' contains an invalid date. Value: '{$value}'"
                );
            }
            return null;
        }

        return $dateTime;
    }

    /**
     * Converts a DateTimeInterface to the target class if needed
     */
    private function convertToTargetClass(DateTimeInterface $dateTime): DateTimeInterface
    {
        // If target is interface or already correct type, return as-is
        if ($this->className === DateTimeInterface::class) {
            return $dateTime;
        }

        if ($dateTime instanceof $this->className) {
            return $dateTime;
        }

        // Convert between DateTime and DateTimeImmutable
        // @phpstan-ignore-next-line instanceof.alwaysFalse, booleanAnd.alwaysFalse
        if ($this->className === DateTime::class && $dateTime instanceof DateTimeImmutable) {
            return DateTime::createFromImmutable($dateTime);
        }

        // @phpstan-ignore-next-line instanceof.alwaysFalse, booleanAnd.alwaysFalse
        if ($this->className === DateTimeImmutable::class && $dateTime instanceof DateTime) {
            return DateTimeImmutable::createFromMutable($dateTime);
        }

        return $dateTime;
    }
}
