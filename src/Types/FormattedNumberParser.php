<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Types;

/**
 * Normalizes human-formatted numbers ("10 000", "1 234,50") to plain numeric strings.
 *
 * Rules (Czech convention):
 * - spaces, NBSP (U+00A0) and narrow NBSP (U+202F) are thousands separators and are removed,
 * - a single comma is the decimal separator,
 * - a dot together with a comma, or more than one comma, is ambiguous and rejected.
 *
 * @internal
 */
final class FormattedNumberParser
{
    private const GROUP_SEPARATORS = [' ', "\u{00A0}", "\u{202F}"];

    /**
     * @return numeric-string|null Plain numeric string ("1234.5"), or null when the value is not a valid formatted number
     */
    public static function normalize(string $value): ?string
    {
        $normalized = str_replace(self::GROUP_SEPARATORS, '', trim($value));

        if (str_contains($normalized, ',')) {
            if (str_contains($normalized, '.') || substr_count($normalized, ',') > 1) {
                return null;
            }
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? $normalized : null;
    }
}
