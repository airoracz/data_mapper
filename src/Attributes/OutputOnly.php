<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Attributes;

use Attribute;

/**
 * The property is written to output but never hydrated from input
 * (server-controlled fields like id, role, createdAt).
 *
 * Example:
 * #[OutputOnly]
 * public string $role = 'user';
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class OutputOnly
{
}
