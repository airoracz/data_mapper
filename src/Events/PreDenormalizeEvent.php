<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Events;

/**
 * Event dispatched before denormalization (array/JSON → object)
 * Allows modification of input data before mapping
 */
class PreDenormalizeEvent extends AbstractEvent
{
    /**
     * @param array<mixed> $data Input data to be denormalized (untrusted - may be a list)
     * @param class-string $className Target class name
     */
    public function __construct(
        public array $data,
        public readonly string $className
    ) {
    }

    /**
     * Modify input data
     *
     * @param array<mixed> $data
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }
}
