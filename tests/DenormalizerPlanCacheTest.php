<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\Filters\ArrayCastFilter;
use Pocta\DataMapper\Attributes\Filters\StringToUpperFilter;
use Pocta\DataMapper\Attributes\Filters\StringTrimFilter;
use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Denormalizer\Denormalizer;
use Pocta\DataMapper\Exceptions\ValidationException;
use Pocta\DataMapper\Types\TypeResolver;

/**
 * Guards for per-class caching in the denormalizer: settings that change between calls,
 * lazily instantiated filters and repeated calls must behave the same on every call.
 */
class DenormalizerPlanCacheTest extends TestCase
{
    private function createDenormalizer(): Denormalizer
    {
        return new Denormalizer(new TypeResolver());
    }

    public function testTogglingPublicPropertiesOnlyOnSameDenormalizer(): void
    {
        $denormalizer = $this->createDenormalizer();
        $input = ['name' => 'John', 'secret' => 'HACKED', 'role' => 'admin'];

        $all = $denormalizer->denormalize($input, DenormPlanDto::class);
        $denormalizer->setPublicPropertiesOnly(true);
        $publicOnly = $denormalizer->denormalize($input, DenormPlanDto::class);
        $denormalizer->setPublicPropertiesOnly(false);
        $allAgain = $denormalizer->denormalize($input, DenormPlanDto::class);

        $this->assertSame('HACKED', $all->getSecret());
        $this->assertSame('admin', $all->getRole());
        $this->assertSame('hash', $publicOnly->getSecret());
        $this->assertSame('user', $publicOnly->getRole());
        $this->assertSame('HACKED', $allAgain->getSecret());
    }

    public function testTogglingStrictModeOnSameDenormalizer(): void
    {
        $denormalizer = $this->createDenormalizer();
        $input = ['name' => 'John', 'unknown' => 'x'];

        $denormalizer->denormalize($input, DenormPlanDto::class);
        $denormalizer->setStrictMode(true);

        try {
            $denormalizer->denormalize($input, DenormPlanDto::class);
            $this->fail('Expected unknown key error in strict mode');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('unknown', $e->getErrors());
        }

        $denormalizer->setStrictMode(false);
        $this->assertSame('JOHN', $denormalizer->denormalize($input, DenormPlanDto::class)->name);
    }

    public function testFiltersAreAppliedOnEveryCall(): void
    {
        $denormalizer = $this->createDenormalizer();

        $first = $denormalizer->denormalize(['name' => '  john  '], DenormPlanDto::class);
        $second = $denormalizer->denormalize(['name' => '  jane '], DenormPlanDto::class);

        $this->assertSame('JOHN', $first->name);
        $this->assertSame('JANE', $second->name);
    }

    public function testMisconfiguredFilterIsIgnoredWhileItsKeyIsMissing(): void
    {
        $dto = $this->createDenormalizer()->denormalize(['name' => 'x'], DenormPlanMisconfiguredFilterDto::class);

        $this->assertSame([], $dto->items);
    }

    public function testMisconfiguredFilterThrowsOnEveryCallWhenKeyIsPresent(): void
    {
        $denormalizer = $this->createDenormalizer();

        foreach ([1, 2] as $attempt) {
            try {
                $denormalizer->denormalize(['items' => ['1']], DenormPlanMisconfiguredFilterDto::class);
                $this->fail("Attempt {$attempt}: expected InvalidArgumentException");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('ArrayCast type must be one of', $e->getMessage());
            }
        }
    }

    public function testMisconfiguredMappingAttributeThrowsOnEveryCall(): void
    {
        $denormalizer = $this->createDenormalizer();

        foreach ([1, 2] as $attempt) {
            try {
                $denormalizer->denormalize([], DenormPlanMisconfiguredMappingDto::class);
                $this->fail("Attempt {$attempt}: expected InvalidArgumentException");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Cannot specify both', $e->getMessage());
            }
        }
    }

    public function testConstructorAndPropertyClassesDenormalizeRepeatedly(): void
    {
        $denormalizer = $this->createDenormalizer();

        foreach (['a', 'b'] as $name) {
            $dto = $denormalizer->denormalize(['title' => $name, 'note' => "n-{$name}"], DenormPlanCtorDto::class);
            $this->assertSame($name, $dto->title);
            $this->assertSame("n-{$name}", $dto->note);
        }
    }
}

class DenormPlanDto
{
    #[StringTrimFilter]
    #[StringToUpperFilter]
    public string $name = '';

    private string $secret = 'hash';

    public function __construct(private string $role = 'user')
    {
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function getRole(): string
    {
        return $this->role;
    }
}

class DenormPlanMisconfiguredFilterDto
{
    public string $name = '';

    /** @var array<mixed> */
    #[ArrayCastFilter('bogus')]
    public array $items = [];
}

class DenormPlanMisconfiguredMappingDto
{
    #[MapProperty(name: 'a', path: 'b')]
    public ?string $broken = null;
}

class DenormPlanCtorDto
{
    public string $note = '';

    public function __construct(public string $title)
    {
    }
}
