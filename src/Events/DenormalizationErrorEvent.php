<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Events;

use Throwable;

/**
 * Event dispatched when denormalization fails
 * Allows custom error handling or recovery
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

    private bool $suppressException = false;

    /**
     * Marks the exception as suppressed. Note: Mapper does not honour this flag - the exception is
     * always re-thrown (returning null would break the non-nullable return type). Use the event for
     * logging/monitoring and catch the exception in the caller.
     */
    public function suppressException(): void
    {
        $this->suppressException = true;
    }

    /**
     * Check if exception is suppressed
     */
    public function isExceptionSuppressed(): bool
    {
        return $this->suppressException;
    }
}
