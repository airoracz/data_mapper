<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Pocta\DataMapper\Attributes\DiscriminatorMap;

/**
 * Abstract base class for polymorphic vehicle types
 */
#[DiscriminatorMap(
    property: 'type',
    mapping: [
        'car' => Car::class,
        'bike' => Bike::class,
        'truck' => Truck::class,
    ]
)]
abstract class Vehicle
{
    protected string $type;

    protected string $brand;

    protected int $year;

    public function __construct(string $type, string $brand, int $year)
    {
        $this->type = $type;
        $this->brand = $brand;
        $this->year = $year;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getBrand(): string
    {
        return $this->brand;
    }

    public function getYear(): int
    {
        return $this->year;
    }
}
