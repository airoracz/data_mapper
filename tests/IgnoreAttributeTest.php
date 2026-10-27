<?php

declare(strict_types=1);

namespace Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\Ignore;
use Pocta\DataMapper\Attributes\InputOnly;
use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Attributes\OutputOnly;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\MapperOptions;
use Pocta\DataMapper\Exceptions\ValidationException;

class IgnoreAttributeTest extends TestCase
{
    public function testIgnoredPropertyIsNotNormalized(): void
    {
        $result = (new Mapper())->toArray(new IgnoreDto());

        $this->assertArrayNotHasKey('internal', $result);
    }

    public function testIgnoredPropertyIsNotDenormalized(): void
    {
        $dto = (new Mapper())->fromArray(['name' => 'John', 'internal' => 'HACKED'], IgnoreDto::class);

        $this->assertSame('kept', $dto->internal);
    }

    public function testOutputOnlyPropertyIsNormalized(): void
    {
        $result = (new Mapper())->toArray(new IgnoreDto());

        $this->assertSame('user', $result['role']);
    }

    public function testOutputOnlyPropertyIsNotDenormalized(): void
    {
        $dto = (new Mapper())->fromArray(['name' => 'John', 'role' => 'admin'], IgnoreDto::class);

        $this->assertSame('user', $dto->role);
    }

    public function testInputOnlyPropertyIsDenormalized(): void
    {
        $dto = (new Mapper())->fromArray(['name' => 'John', 'password' => 'secret'], IgnoreDto::class);

        $this->assertSame('secret', $dto->password);
    }

    public function testInputOnlyPropertyIsNotNormalized(): void
    {
        $dto = new IgnoreDto();
        $dto->password = 'secret';

        $result = (new Mapper())->toArray($dto);

        $this->assertArrayNotHasKey('password', $result);
    }

    public function testIgnoreWinsOverMapPropertyOptInInPublicOnlyMode(): void
    {
        $mapper = new Mapper(new MapperOptions(publicPropertiesOnly: true));

        $result = $mapper->toArray(new IgnorePrivateDto());

        $this->assertSame([], $result);
    }

    public function testConstructorDoesNotHydrateOutputOnlyPromotedParameter(): void
    {
        $dto = (new Mapper())->fromArray(['name' => 'John', 'role' => 'admin'], IgnoreCtorDto::class);

        $this->assertSame('user', $dto->role);
    }

    public function testConstructorHydratesInputOnlyPromotedParameter(): void
    {
        $dto = (new Mapper())->fromArray(['name' => 'John', 'password' => 'secret'], IgnoreCtorDto::class);

        $this->assertSame('secret', $dto->password);
    }

    public function testConstructorDoesNotHydrateNonPromotedParameterOfOutputOnlyProperty(): void
    {
        $dto = (new Mapper())->fromArray(['name' => 'John', 'role' => 'admin'], IgnoreAssignedInCtorDto::class);

        $this->assertSame('user', $dto->role);
    }

    public function testConstructorDoesNotHydrateNonPromotedParameterOfIgnoredProperty(): void
    {
        $dto = (new Mapper())->fromArray(['name' => 'John', 'internal' => 'HACKED'], IgnoreAssignedInCtorDto::class);

        $this->assertSame('kept', $dto->internal);
    }

    public function testConstructorHydratesNonPromotedParameterWithoutMatchingProperty(): void
    {
        $dto = (new Mapper())->fromArray(['name' => 'John'], IgnoreAssignedInCtorDto::class);

        $this->assertSame('John', $dto->name);
    }

    public function testIgnoredVariadicParameterReceivesNoArguments(): void
    {
        $dto = (new Mapper())->fromArray(['name' => 'John', 'tags' => ['a']], IgnoreVariadicDto::class);

        $this->assertSame('John', $dto->name);
        $this->assertSame([], $dto->tags);
    }

    public function testRequiredIgnoredPromotedParameterIsConfigurationError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('$id');

        (new Mapper())->fromArray(['id' => 1], IgnoreRequiredCtorDto::class);
    }

    public function testStrictModeRejectsIgnoredKeyAsUnknown(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Unknown key 'internal'");

        (new Mapper(MapperOptions::withStrictMode()))->fromArray(['name' => 'John', 'internal' => 'x'], IgnoreDto::class);
    }

    public function testStrictModeAcceptsAndDiscardsOutputOnlyPromotedParameterKey(): void
    {
        $dto = (new Mapper(MapperOptions::withStrictMode()))->fromArray(
            ['name' => 'John', 'role' => 'admin'],
            IgnoreCtorDto::class
        );

        $this->assertSame('user', $dto->role);
    }

    public function testStrictModeAcceptsAndDiscardsOutputOnlyPropertyKey(): void
    {
        $dto = (new Mapper(MapperOptions::withStrictMode()))->fromArray(
            ['name' => 'John', 'role' => 'admin'],
            IgnoreDto::class
        );

        $this->assertSame('user', $dto->role);
    }

    public function testStrictModeAcceptsOutputOnlyKeyOfNonPromotedConstructorParameter(): void
    {
        $dto = (new Mapper(MapperOptions::withStrictMode()))->fromArray(
            ['name' => 'John', 'role' => 'admin'],
            IgnoreAssignedInCtorDto::class
        );

        $this->assertSame('user', $dto->role);
    }

    public function testMergeInStrictModeAcceptsAndDiscardsOutputOnlyKey(): void
    {
        $dto = (new Mapper(MapperOptions::withStrictMode()))->merge(['role' => 'admin'], new IgnoreDto());

        $this->assertSame('user', $dto->role);
    }

    public function testMergeInStrictModeRejectsIgnoredKey(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Unknown key 'internal'");

        (new Mapper(MapperOptions::withStrictMode()))->merge(['internal' => 'x'], new IgnoreDto());
    }

    public function testStrictModeRoundTripOfOutputWorks(): void
    {
        $mapper = new Mapper(MapperOptions::withStrictMode());
        $original = new IgnoreDto();
        $original->name = 'John';

        $dto = $mapper->fromJson($mapper->toJson($original), IgnoreDto::class);

        $this->assertSame('John', $dto->name);
        $this->assertSame('user', $dto->role);
    }

    public function testStrictModeAcceptsInputOnlyKey(): void
    {
        $dto = (new Mapper(MapperOptions::withStrictMode()))->fromArray(
            ['name' => 'John', 'password' => 'secret'],
            IgnoreDto::class
        );

        $this->assertSame('secret', $dto->password);
    }

    public function testMergeDoesNotSetOutputOnlyProperty(): void
    {
        $dto = (new Mapper())->merge(['role' => 'admin', 'internal' => 'HACKED'], new IgnoreDto());

        $this->assertSame('user', $dto->role);
        $this->assertSame('kept', $dto->internal);
    }
}

class IgnoreDto
{
    public string $name = 'default';

    #[Ignore]
    public string $internal = 'kept';

    #[OutputOnly]
    public string $role = 'user';

    #[InputOnly]
    public ?string $password = null;
}

class IgnorePrivateDto
{
    #[Ignore]
    #[MapProperty]
    private string $secret = 'hash';

    public function getSecret(): string
    {
        return $this->secret;
    }
}

class IgnoreCtorDto
{
    public function __construct(
        public string $name,
        #[OutputOnly]
        public string $role = 'user',
        #[InputOnly]
        public ?string $password = null,
    ) {
    }
}

class IgnoreRequiredCtorDto
{
    public function __construct(
        #[Ignore]
        public int $id,
    ) {
    }
}

class IgnoreAssignedInCtorDto
{
    public string $name;

    #[OutputOnly]
    public string $role;

    #[Ignore]
    public string $internal;

    public function __construct(string $name, string $role = 'user', string $internal = 'kept')
    {
        $this->name = $name;
        $this->role = $role;
        $this->internal = $internal;
    }
}

class IgnoreVariadicDto
{
    /** @var array<int|string, string> */
    public array $tags;

    public function __construct(
        public string $name,
        #[Ignore]
        string ...$tags,
    ) {
        $this->tags = $tags;
    }
}
