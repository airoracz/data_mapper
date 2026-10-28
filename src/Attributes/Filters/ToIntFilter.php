<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Attributes\Filters;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ToIntFilter implements FilterInterface
{
    public function apply(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_numeric($value) || is_bool($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return $value; // keep empty string; pair with ToNull to drop
            }

            // Remove thousand separators and normalize comma decimal
            $normalized = str_replace([' ', "\u{00A0}"], '', $trimmed);
            $normalized = str_replace(',', '.', $normalized);
            if (is_numeric($normalized)) {
                return (int) ((float) $normalized);
            }
        }

        return $value;
    }
}
