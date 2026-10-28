<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Types;

use InvalidArgumentException;

class FloatType extends AbstractType
{
    private bool $parseFormattedNumbers = false;

    /**
     * Accept formatted numeric strings ("1 234,50"); see FormattedNumberParser.
     */
    public function setParseFormattedNumbers(bool $parseFormattedNumbers): void
    {
        $this->parseFormattedNumbers = $parseFormattedNumbers;
    }

    public function getName(): string
    {
        return 'float';
    }

    public function getAliases(): array
    {
        return ['float', 'double'];
    }

    protected function denormalizeValue(mixed $value, string $fieldName): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if ($this->parseFormattedNumbers && is_string($value)) {
            $normalized = FormattedNumberParser::normalize($value);
            if ($normalized !== null) {
                return (float) $normalized;
            }
        }

        throw new InvalidArgumentException(
            "Cannot cast value of field '{$fieldName}' to float"
        );
    }

    protected function normalizeValue(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        // This should not happen in normal usage, but handle it for type safety
        return 0.0;
    }
}
