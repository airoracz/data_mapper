<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Types;

use InvalidArgumentException;

class IntType extends AbstractType
{
    private bool $parseFormattedNumbers = false;

    /**
     * Accept formatted numeric strings ("10 000", "12,0"); see FormattedNumberParser.
     */
    public function setParseFormattedNumbers(bool $parseFormattedNumbers): void
    {
        $this->parseFormattedNumbers = $parseFormattedNumbers;
    }

    public function getName(): string
    {
        return 'int';
    }

    public function getAliases(): array
    {
        return ['int', 'integer'];
    }

    protected function denormalizeValue(mixed $value, string $fieldName): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if ($this->parseFormattedNumbers && is_string($value)) {
            $normalized = FormattedNumberParser::normalize($value);
            // A fractional part is accepted only when it is zero - never truncate silently
            if ($normalized !== null && preg_match('/^-?\d+(\.0*)?$/', $normalized) === 1) {
                return (int) $normalized;
            }
        }

        throw new InvalidArgumentException(
            "Cannot cast value of field '{$fieldName}' to integer"
        );
    }

    protected function normalizeValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        // This should not happen in normal usage, but handle it for type safety
        return 0;
    }
}
