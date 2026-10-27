<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Attributes;

use Attribute;

/**
 * Excludes the property from mapping in both directions: it is never written
 * to output and never hydrated from input.
 *
 * Example:
 * #[Ignore]
 * public string $internalNote;
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Ignore
{
}
