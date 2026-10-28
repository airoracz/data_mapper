<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\Filters\StringToUpperFilter;
use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\MapperOptions;
use Pocta\DataMapper\Normalizer\Normalizer;

/**
 * Guards for per-class caching in the normalizer: settings that change between calls
 * and misconfigured attributes must behave the same on every call.
 */
class NormalizerPlanCacheTest extends TestCase
{
    public function testTogglingPublicPropertiesOnlyOnSameNormalizer(): void
    {
        $normalizer = new Normalizer();
        $dto = new PlanCacheDto();

        $all = $normalizer->normalize($dto);
        $normalizer->setPublicPropertiesOnly(true);
        $publicOnly = $normalizer->normalize($dto);
        $normalizer->setPublicPropertiesOnly(false);
        $allAgain = $normalizer->normalize($dto);

        $this->assertArrayHasKey('secret', $all);
        $this->assertArrayNotHasKey('secret', $publicOnly);
        $this->assertSame($all, $allAgain);
    }

    public function testTogglingSymmetricPathsOnSameNormalizer(): void
    {
        $normalizer = new Normalizer();
        $dto = new PlanCacheDto();

        $flat = $normalizer->normalize($dto);
        $normalizer->setSymmetricPropertyPaths(true);
        $nested = $normalizer->normalize($dto);

        $this->assertSame('Prague', $flat['city']);
        $this->assertSame(['city' => 'Prague'], $nested['address']);
    }

    public function testToArrayAfterToCsvKeepsSymmetricPaths(): void
    {
        $mapper = new Mapper(new MapperOptions(symmetricPropertyPaths: true));
        $dto = new PlanCacheDto();

        $mapper->toCsv([$dto]);
        $result = $mapper->toArray($dto);

        $this->assertSame(['city' => 'Prague'], $result['address']);
    }

    public function testFiltersAreAppliedOnEveryCall(): void
    {
        $normalizer = new Normalizer();

        $first = $normalizer->normalize(new PlanCacheDto());
        $second = $normalizer->normalize(new PlanCacheDto());

        $this->assertSame('HELLO', $first['shout']);
        $this->assertSame('HELLO', $second['shout']);
    }

    public function testMisconfiguredAttributeThrowsOnEveryCall(): void
    {
        $normalizer = new Normalizer();

        foreach ([1, 2] as $attempt) {
            try {
                $normalizer->normalize(new PlanCacheMisconfiguredDto());
                $this->fail("Attempt {$attempt}: expected InvalidArgumentException");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Cannot specify both', $e->getMessage());
            }
        }
    }

    public function testDifferentClassesDoNotShareCachedPlan(): void
    {
        $normalizer = new Normalizer();

        $this->assertSame(['value' => 'a'], $normalizer->normalize(new PlanCacheOtherA()));
        $this->assertSame(['renamed' => 'b'], $normalizer->normalize(new PlanCacheOtherB()));
    }
}

class PlanCacheDto
{
    public string $name = 'n';

    private string $secret = 's';

    #[MapProperty(path: 'address.city')]
    public string $city = 'Prague';

    #[StringToUpperFilter]
    public string $shout = 'hello';

    public function getSecret(): string
    {
        return $this->secret;
    }
}

class PlanCacheMisconfiguredDto
{
    #[MapProperty(name: 'a', path: 'b')]
    public string $broken = 'x';
}

class PlanCacheOtherA
{
    public string $value = 'a';
}

class PlanCacheOtherB
{
    #[MapProperty(name: 'renamed')]
    public string $value = 'b';
}
