<?php

declare(strict_types=1);

namespace Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\MapperOptions;
use Pocta\DataMapper\Exceptions\ValidationException;

class PublicPropertiesOnlyTest extends TestCase
{
    private function createMapper(bool $strictMode = false): Mapper
    {
        return new Mapper(new MapperOptions(strictMode: $strictMode, publicPropertiesOnly: true));
    }

    public function testOptionIsDisabledByDefault(): void
    {
        $this->assertFalse((new MapperOptions())->publicPropertiesOnly);
    }

    public function testWithPreservesOption(): void
    {
        $options = (new MapperOptions(publicPropertiesOnly: true))->with(strictMode: true);

        $this->assertTrue($options->publicPropertiesOnly);
        $this->assertTrue($options->strictMode);
    }

    public function testDefaultModeStillMapsNonPublicProperties(): void
    {
        $mapper = new Mapper();

        $result = $mapper->toArray(new VisibilityDto());

        $this->assertSame(
            ['name' => 'default', 'secret' => 'hash', 'internal' => 'note', 'exposed' => 'visible'],
            $result
        );
    }

    public function testNormalizeSkipsNonPublicProperties(): void
    {
        $result = $this->createMapper()->toArray(new VisibilityDto());

        $this->assertArrayNotHasKey('secret', $result);
        $this->assertArrayNotHasKey('internal', $result);
    }

    public function testNormalizeIncludesNonPublicPropertyWithMapPropertyAttribute(): void
    {
        $result = $this->createMapper()->toArray(new VisibilityDto());

        $this->assertSame(['name' => 'default', 'exposed' => 'visible'], $result);
    }

    public function testDenormalizeDoesNotSetNonPublicProperties(): void
    {
        $dto = $this->createMapper()->fromArray(
            ['name' => 'John', 'secret' => 'HACKED', 'internal' => 'HACKED'],
            VisibilityDto::class
        );

        $this->assertSame('John', $dto->name);
        $this->assertSame('hash', $dto->getSecret());
        $this->assertSame('note', $dto->getInternal());
    }

    public function testDenormalizeSetsNonPublicPropertyWithMapPropertyAttribute(): void
    {
        $dto = $this->createMapper()->fromArray(['name' => 'John', 'exposed' => 'set'], VisibilityDto::class);

        $this->assertSame('set', $dto->getExposed());
    }

    public function testConstructorDoesNotHydrateNonPublicPromotedParameter(): void
    {
        $dto = $this->createMapper()->fromArray(
            ['name' => 'John', 'role' => 'admin'],
            VisibilityCtorDto::class
        );

        $this->assertSame('John', $dto->name);
        $this->assertSame('user', $dto->getRole());
    }

    public function testConstructorHydratesNonPublicPromotedParameterWithMapPropertyAttribute(): void
    {
        $dto = $this->createMapper()->fromArray(
            ['name' => 'John', 'owner_id' => 42],
            VisibilityCtorDto::class
        );

        $this->assertSame(42, $dto->getOwnerId());
    }

    public function testConstructorStillHydratesNonPromotedParameter(): void
    {
        $dto = $this->createMapper()->fromArray(['title' => 'Hello'], VisibilityPlainCtorDto::class);

        $this->assertSame('Hello', $dto->getTitle());
    }

    public function testRequiredNonPublicPromotedParameterWithoutAttributeIsConfigurationError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('$token');

        $this->createMapper()->fromArray(['token' => 'abc'], VisibilityRequiredPrivateCtorDto::class);
    }

    public function testStrictModeRejectsNonPublicPropertyKeyAsUnknown(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Unknown key 'secret'");

        $this->createMapper(strictMode: true)->fromArray(['name' => 'John', 'secret' => 'x'], VisibilityDto::class);
    }

    public function testStrictModeRejectsNonPublicPromotedParameterKeyAsUnknown(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Unknown key 'role'");

        $this->createMapper(strictMode: true)->fromArray(['name' => 'John', 'role' => 'admin'], VisibilityCtorDto::class);
    }

    public function testStrictModeAcceptsNonPublicPropertyWithMapPropertyAttribute(): void
    {
        $dto = $this->createMapper(strictMode: true)->fromArray(
            ['name' => 'John', 'exposed' => 'set'],
            VisibilityDto::class
        );

        $this->assertSame('set', $dto->getExposed());
    }

    public function testMergeDoesNotSetNonPublicProperties(): void
    {
        $dto = $this->createMapper()->merge(['secret' => 'HACKED', 'internal' => 'HACKED'], new VisibilityDto());

        $this->assertSame('hash', $dto->getSecret());
        $this->assertSame('note', $dto->getInternal());
    }

    public function testMergeSetsNonPublicPropertyWithMapPropertyAttribute(): void
    {
        $dto = $this->createMapper()->merge(['exposed' => 'merged'], new VisibilityDto());

        $this->assertSame('merged', $dto->getExposed());
    }
}

class VisibilityDto
{
    public string $name = 'default';

    private string $secret = 'hash';

    protected string $internal = 'note';

    #[MapProperty]
    private string $exposed = 'visible';

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function getInternal(): string
    {
        return $this->internal;
    }

    public function getExposed(): string
    {
        return $this->exposed;
    }
}

class VisibilityCtorDto
{
    public function __construct(
        public string $name,
        private string $role = 'user',
        #[MapProperty(name: 'owner_id')]
        private ?int $ownerId = null,
    ) {
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getOwnerId(): ?int
    {
        return $this->ownerId;
    }
}

class VisibilityPlainCtorDto
{
    private string $title;

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    public function getTitle(): string
    {
        return $this->title;
    }
}

class VisibilityRequiredPrivateCtorDto
{
    public function __construct(
        private string $token,
    ) {
    }

    public function getToken(): string
    {
        return $this->token;
    }
}
