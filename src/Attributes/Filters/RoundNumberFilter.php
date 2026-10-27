<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Attributes\Filters;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class RoundNumberFilter implements FilterInterface
{
    /** @var 'half_up'|'half_down'|'half_even'|'half_odd' */
    public readonly string $mode;

    public function __construct(
        public readonly int $precision = 0,
        string $mode = 'half_up'
    ) {
        if (!in_array($mode, ['half_up', 'half_down', 'half_even', 'half_odd'], true)) {
            $mode = 'half_up';
        }
        $this->mode = $mode;
    }

    public function apply(mixed $value): mixed
    {
        if (!is_numeric($value)) {
            return $value;
        }
        $map = [
            'half_up' => PHP_ROUND_HALF_UP,
            'half_down' => PHP_ROUND_HALF_DOWN,
            'half_even' => PHP_ROUND_HALF_EVEN,
            'half_odd' => PHP_ROUND_HALF_ODD,
        ];
        $modeConst = $map[$this->mode];
        $res = round((float) $value, $this->precision, $modeConst);
        return floor($res) == $res ? (int) $res : $res;
    }
}
