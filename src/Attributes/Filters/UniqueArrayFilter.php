<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Attributes\Filters;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class UniqueArrayFilter implements FilterInterface
{
    public function __construct(
        public readonly bool $strict = true
    ) {
    }

    public function apply(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        // Input is untrusted mixed data; non-strict mode compares string casts, as it always has
        // @phpstan-ignore argument.type
        return array_values(array_unique($value, $this->strict ? SORT_REGULAR : SORT_STRING));
    }
}
