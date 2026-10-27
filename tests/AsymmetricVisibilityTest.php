<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\MapperOptions;
use Tests\Fixtures\AsymmetricVisibilityDto;

#[RequiresPhp('>= 8.4')]
class AsymmetricVisibilityTest extends TestCase
{
    private function createMapper(): Mapper
    {
        return new Mapper(new MapperOptions(publicPropertiesOnly: true));
    }

    public function testPrivateSetPropertyIsNotHydratedInPublicOnlyMode(): void
    {
        $dto = $this->createMapper()->fromArray(['role' => 'admin'], AsymmetricVisibilityDto::class);

        $this->assertSame('user', $dto->role);
    }

    public function testProtectedSetPropertyIsNotHydratedInPublicOnlyMode(): void
    {
        $dto = $this->createMapper()->fromArray(['status' => 'banned'], AsymmetricVisibilityDto::class);

        $this->assertSame('active', $dto->status);
    }

    public function testPrivateSetPromotedParameterIsNotHydratedInPublicOnlyMode(): void
    {
        $dto = $this->createMapper()->fromArray(['tier' => 'premium'], AsymmetricVisibilityDto::class);

        $this->assertSame('free', $dto->tier);
    }

    public function testPrivateSetPropertyWithMapPropertyIsHydrated(): void
    {
        $dto = $this->createMapper()->fromArray(['nickname' => 'neo'], AsymmetricVisibilityDto::class);

        $this->assertSame('neo', $dto->nickname);
    }

    public function testPublicReadonlyPropertyIsStillHydrated(): void
    {
        $dto = $this->createMapper()->fromArray(['id' => 'abc'], AsymmetricVisibilityDto::class);

        $this->assertSame('abc', $dto->id);
    }

    public function testPrivateSetPropertyIsStillNormalized(): void
    {
        $result = $this->createMapper()->toArray(new AsymmetricVisibilityDto());

        $this->assertSame('user', $result['role']);
        $this->assertSame('active', $result['status']);
    }
}
