<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Debug;

/**
 * Profiler for measuring performance of mapper operations
 */
class Profiler
{
    /** @var array<string, array{count: int, totalTime: float, totalMemory: int}> */
    private array $metrics = [];

    /** @var array<string, list<array{0: float, 1: int}>> Start time/memory per running operation (innermost last) */
    private array $starts = [];

    /** @var array<string> Stack of currently running operations */
    private array $stack = [];

    private bool $enabled = true;

    public function __construct(bool $enabled = true)
    {
        $this->enabled = $enabled;
    }

    /**
     * Enable or disable profiler
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Check if profiler is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Start profiling an operation
     */
    public function start(string $operation): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!isset($this->metrics[$operation])) {
            $this->metrics[$operation] = [
                'count' => 0,
                'totalTime' => 0.0,
                'totalMemory' => 0,
            ];
        }

        // A stack per operation name, so nested operations with the same name are measured separately
        $this->starts[$operation][] = [microtime(true), memory_get_usage(true)];
        $this->stack[] = $operation;
    }

    /**
     * Stop profiling an operation
     */
    public function stop(string $operation): void
    {
        if (!$this->enabled) {
            return;
        }

        if (empty($this->starts[$operation]) || !isset($this->metrics[$operation])) {
            return; // Operation was not started
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        [$startTime, $startMemory] = array_pop($this->starts[$operation]);
        $duration = $endTime - $startTime;
        $memoryUsed = max(0, $endMemory - $startMemory);

        $this->metrics[$operation]['count']++;
        $this->metrics[$operation]['totalTime'] += $duration;
        $this->metrics[$operation]['totalMemory'] += $memoryUsed;

        // Remove the innermost occurrence from the stack
        $keys = array_keys($this->stack, $operation, true);
        if ($keys !== []) {
            array_splice($this->stack, (int) end($keys), 1);
        }
    }

    /**
     * Profile a callable
     *
     * @template T
     * @param string $operation Operation name
     * @param callable(): T $callable
     * @return T
     */
    public function profile(string $operation, callable $callable): mixed
    {
        $this->start($operation);
        try {
            return $callable();
        } finally {
            $this->stop($operation);
        }
    }

    /**
     * Get metrics for a specific operation
     *
     * @return array{count: int, totalTime: float, totalMemory: int, avgTime: float, avgMemory: float}|null
     */
    public function getMetrics(string $operation): ?array
    {
        if (!isset($this->metrics[$operation])) {
            return null;
        }

        $data = $this->metrics[$operation];
        $count = $data['count'];

        return [
            'count' => $count,
            'totalTime' => $data['totalTime'],
            'totalMemory' => $data['totalMemory'],
            'avgTime' => $count > 0 ? $data['totalTime'] / $count : 0,
            'avgMemory' => $count > 0 ? $data['totalMemory'] / $count : 0,
        ];
    }

    /**
     * Get all metrics
     *
     * @return array<string, array{count: int, totalTime: float, totalMemory: int, avgTime: float, avgMemory: float}>
     */
    public function getAllMetrics(): array
    {
        $result = [];
        foreach (array_keys($this->metrics) as $operation) {
            $metrics = $this->getMetrics($operation);
            if ($metrics !== null) {
                $result[$operation] = $metrics;
            }
        }
        return $result;
    }

    /**
     * Get current stack of running operations
     *
     * @return array<string>
     */
    public function getStack(): array
    {
        return $this->stack;
    }

    /**
     * Clear all metrics
     */
    public function clear(): void
    {
        $this->metrics = [];
        $this->starts = [];
        $this->stack = [];
    }

    /**
     * Get a formatted report
     */
    public function getReport(): ProfileReport
    {
        return new ProfileReport($this->getAllMetrics());
    }

    /**
     * Get summary statistics
     *
     * @return array{totalOperations: int, totalTime: float, totalMemory: int, peakMemory: int}
     */
    public function getSummary(): array
    {
        $totalOps = 0;
        $totalTime = 0.0;
        $totalMemory = 0;

        foreach ($this->metrics as $data) {
            $totalOps += $data['count'];
            $totalTime += $data['totalTime'];
            $totalMemory += $data['totalMemory'];
        }

        return [
            'totalOperations' => $totalOps,
            'totalTime' => $totalTime,
            'totalMemory' => $totalMemory,
            'peakMemory' => memory_get_peak_usage(true),
        ];
    }
}
