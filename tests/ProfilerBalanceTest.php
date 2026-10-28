<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Debug\Profiler;
use Pocta\DataMapper\Events\PreDenormalizeEvent;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\MapperOptions;
use Pocta\DataMapper\Validation\NotBlank;

/**
 * Failed operations must not leave entries on the profiler stack (it would grow per failed request
 * in a long-running worker), and nested operations with the same name must both be measured.
 */
class ProfilerBalanceTest extends TestCase
{
    public function testNestedOperationsWithSameNameAreBothCounted(): void
    {
        $profiler = new Profiler();

        $profiler->profile('op', fn() => $profiler->profile('op', fn() => null));

        $this->assertSame(2, $profiler->getMetrics('op')['count'] ?? null);
        $this->assertSame([], $profiler->getStack());
    }

    public function testStackIsEmptyAfterDenormalizationError(): void
    {
        $profiler = new Profiler();
        $mapper = new Mapper(profiler: $profiler);

        $this->callIgnoringException(fn() => $mapper->fromArray([], ProfilerRequiredDto::class));

        $this->assertSame([], $profiler->getStack());
    }

    public function testStackIsEmptyAfterValidationError(): void
    {
        $profiler = new Profiler();
        $mapper = new Mapper(MapperOptions::withAutoValidation(), profiler: $profiler);

        $this->callIgnoringException(fn() => $mapper->fromArray(['name' => ''], ProfilerRequiredDto::class));

        $this->assertSame([], $profiler->getStack());
    }

    public function testStackIsEmptyWhenPreDenormalizeListenerThrows(): void
    {
        $profiler = new Profiler();
        $mapper = new Mapper(profiler: $profiler);
        $mapper->addEventListener(PreDenormalizeEvent::class, function (): void {
            throw new \RuntimeException('listener failed');
        });

        $this->callIgnoringException(fn() => $mapper->fromArray(['name' => 'x'], ProfilerRequiredDto::class));

        $this->assertSame([], $profiler->getStack());
    }

    public function testStackIsEmptyAfterNormalizationError(): void
    {
        $profiler = new Profiler();
        $mapper = new Mapper(profiler: $profiler);

        $this->callIgnoringException(fn() => $mapper->toArray(new ProfilerBrokenDto()));

        $this->assertSame([], $profiler->getStack());
    }

    public function testSuccessfulOperationsAreStillMeasured(): void
    {
        $profiler = new Profiler();
        $mapper = new Mapper(MapperOptions::withAutoValidation(), profiler: $profiler);

        $mapper->fromArray(['name' => 'x'], ProfilerRequiredDto::class);

        foreach (['fromArray', 'denormalize', 'validation'] as $operation) {
            $this->assertSame(1, $profiler->getMetrics($operation)['count'] ?? null, $operation);
        }
        $this->assertSame([], $profiler->getStack());
    }

    private function callIgnoringException(callable $callable): void
    {
        try {
            $callable();
            $this->fail('Expected an exception');
        } catch (\Throwable $e) {
            if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $e;
            }
        }
    }
}

class ProfilerRequiredDto
{
    #[NotBlank]
    public string $name;
}

class ProfilerBrokenDto
{
    #[\Pocta\DataMapper\Attributes\MapProperty(name: 'a', path: 'b')]
    public string $broken = 'x';
}
