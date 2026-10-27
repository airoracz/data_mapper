<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Attributes;

use Attribute;

/**
 * The property is hydrated from input but never written to output
 * (write-only fields like password).
 *
 * Example:
 * #[InputOnly]
 * public string $password;
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InputOnly
{
}
