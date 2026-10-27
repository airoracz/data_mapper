<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Attributes\Filters;

use Attribute;
use DateTimeInterface;
use DateTimeImmutable;
use DateTime;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class TruncateDateTimeFilter implements FilterInterface
{
    /** @var 'minute'|'hour'|'day'|'month'|'year' */
    public readonly string $unit;

    public function __construct(string $unit)
    {
        if (!in_array($unit, ['minute', 'hour', 'day', 'month', 'year'], true)) {
            $unit = 'day';
        }
        $this->unit = $unit;
    }

    public function apply(mixed $value): mixed
    {
        if (!$value instanceof DateTimeInterface) {
            return $value;
        }

        $y = (int)$value->format('Y');
        $m = (int)$value->format('m');
        $d = (int)$value->format('d');
        $H = (int)$value->format('H');
        $i = (int)$value->format('i');
        $s = 0;

        switch ($this->unit) {
            case 'minute':
                $H = (int)$value->format('H');
                $i = (int)$value->format('i');
                $s = 0;
                break;
            case 'hour':
                $i = 0;
                $s = 0;
                break;
            case 'day':
                $H = 0; $i = 0; $s = 0;
                break;
            case 'month':
                $d = 1; $H = 0; $i = 0; $s = 0;
                break;
            case 'year':
                $m = 1; $d = 1; $H = 0; $i = 0; $s = 0;
                break;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->setDate($y, $m, $d)->setTime($H, $i, $s, 0);
        }
        // DateTime instance
        $clone = clone $value;
        $clone->setDate($y, $m, $d);
        $clone->setTime($H, $i, $s, 0);
        return $clone;
    }
}
