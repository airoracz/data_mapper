<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Events;

use Throwable;

/**
 * Event dispatched when denormalization fails - for logging/monitoring.
 * The exception is always re-thrown afterwards.
 */
class DenormalizationErrorEvent extends AbstractEvent
{
    /**
     * @param Throwable $exception The exception that occurred
     * @param array<mixed> $data Input data that caused the error
     * @param class-string $className Target class name
     * @param object|null $partialObject Partially created object (if any)
     */
    public function __construct(
        public readonly Throwable $exception,
        public readonly array $data,
        public readonly string $className,
        public readonly ?object $partialObject = null
    ) {
    }
}
