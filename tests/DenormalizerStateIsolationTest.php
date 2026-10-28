<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\HydrationMode;
use Pocta\DataMapper\Attributes\MapPropertyWithFunction;
use Pocta\DataMapper\Denormalizer\Denormalizer;
use Pocta\DataMapper\Exceptions\CircularReferenceException;
use Pocta\DataMapper\Exceptions\ValidationException;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\Types\TypeResolver;

/**
 * A Mapper / Denormalizer instance is reused across calls (DI singleton, long-running worker).
 * State from one call must not leak into the next one.
 */
class DenormalizerStateIsolationTest extends TestCase
{
    public function testRepeatedNestedValidationErrorIsReportedOnEveryCall(): void
    {
        $mapper = new Mapper();

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $mapper->fromArray(['leaf' => []], IsolationLeafHolder::class);
                $this->fail("Attempt {$attempt}: missing leaf.n was accepted");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('leaf.n', $e->getErrors());
            }
        }
    }

    public function testNestedValidationErrorIsReportedAfterCircularReferenceException(): void
    {
        $mapper = new Mapper();

        try {
            $mapper->fromArray(['child' => ['child' => ['child' => []]]], IsolationSelfNested::class);
            $this->fail('Expected CircularReferenceException');
        } catch (CircularReferenceException) {
        }

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $mapper->fromArray(['leaf' => []], IsolationLeafHolder::class);
                $this->fail("Attempt {$attempt}: missing leaf.n was accepted after a circular reference error");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('leaf.n', $e->getErrors());
            }
        }
    }

    public function testSelfNestedClassIsStillRejectedAsCircularReference(): void
    {
        $this->expectException(CircularReferenceException::class);

        (new Mapper())->fromArray(['child' => ['child' => []]], IsolationSelfNested::class);
    }

    public function testDirectDenormalizerCallSeesItsOwnRootPayload(): void
    {
        $denormalizer = new Denormalizer(new TypeResolver());

        $first = $denormalizer->denormalize(['tag' => 'first'], IsolationRootReader::class);
        $second = $denormalizer->denormalize(['tag' => 'second'], IsolationRootReader::class);

        $this->assertSame('first', $first->seen);
        $this->assertSame('second', $second->seen);
    }
}

class IsolationLeaf
{
    public string $n;
}

class IsolationLeafHolder
{
    public IsolationLeaf $leaf;
}

class IsolationSelfNested
{
    public ?IsolationSelfNested $child = null;
}

class IsolationRootReader
{
    public string $tag = '';

    #[MapPropertyWithFunction([IsolationRootReader::class, 'readRootTag'], HydrationMode::FULL)]
    public string $seen = '';

    /**
     * @param array<string, mixed> $payload
     */
    public static function readRootTag(array $payload): string
    {
        $tag = $payload['tag'] ?? 'none';

        return is_string($tag) ? $tag : 'none';
    }
}
