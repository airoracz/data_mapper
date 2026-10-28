<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\PropertyPathResolver;

/**
 * Guards for memoized path parsing: repeated and invalid paths behave the same on every call.
 */
class PropertyPathMemoTest extends TestCase
{
    public function testRepeatedResolutionReturnsSameResults(): void
    {
        $data = ['user' => ['addresses' => [['street' => 'Main']]]];

        foreach ([1, 2] as $attempt) {
            $this->assertTrue(PropertyPathResolver::exists($data, 'user.addresses[0].street'), "attempt {$attempt}");
            $this->assertSame('Main', PropertyPathResolver::resolve($data, 'user.addresses[0].street'));
            $this->assertFalse(PropertyPathResolver::exists($data, 'user.addresses[1].street'));
        }
    }

    public function testInvalidPathThrowsOnEveryCall(): void
    {
        foreach ([1, 2] as $attempt) {
            try {
                PropertyPathResolver::resolve(['a' => 1], 'a[x]');
                $this->fail("Attempt {$attempt}: expected InvalidArgumentException");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('numeric', $e->getMessage());
            }
        }
    }

    public function testManyDistinctPathsStillResolve(): void
    {
        $data = [];
        for ($i = 0; $i < 3000; $i++) {
            $data["k{$i}"] = ['v' => $i];
        }

        for ($i = 0; $i < 3000; $i++) {
            $this->assertSame($i, PropertyPathResolver::resolve($data, "k{$i}.v"));
        }
    }
}
