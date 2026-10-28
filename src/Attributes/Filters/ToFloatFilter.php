<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Attributes\Filters;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ToFloatFilter implements FilterInterface
{
    public function apply(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_numeric($value) || is_bool($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return $value; // leave empty string as-is; combine with ToNull if desired
            }
            // Basic normalization for commas as decimal separators
            $normalized = str_replace([' ', "\u{00A0}"], '', $trimmed);
            $normalized = str_replace(',', '.', $normalized);
            if (is_numeric($normalized)) {
                return (float) $normalized;
            }
        }

        return $value; // unsupported types unchanged
    }
}
