<?php

declare(strict_types=1);

namespace Tests;

use Attribute;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Validation\AssertInterface;
use Pocta\DataMapper\Validation\Callback;
use Pocta\DataMapper\Validation\Validator;

/**
 * Validator attributes may depend on construction time (e.g. DateTimeLessThan('now')),
 * so they must be instantiated on every validation - per-class caching may only cover reflection.
 */
class ValidatorInstantiationTest extends TestCase
{
    protected function setUp(): void
    {
        CountingAssert::$instances = 0;
    }

    public function testAssertAttributeIsInstantiatedOnEveryValidation(): void
    {
        $validator = new Validator();

        $first = $validator->validate(new CountingAssertDto(), throw: false);
        $second = $validator->validate(new CountingAssertDto(), throw: false);

        $this->assertSame(['value' => 'instance 1'], $first);
        $this->assertSame(['value' => 'instance 2'], $second);
    }

    public function testCallbackMethodsRunOnEveryValidation(): void
    {
        $validator = new Validator();
        $dto = new CallbackCountingDto();

        $validator->validate($dto, throw: false);
        $validator->validate($dto, throw: false);

        $this->assertSame(2, $dto->calls);
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class CountingAssert implements AssertInterface
{
    public static int $instances = 0;

    private int $instance;

    /** @param array<string> $groups */
    public function __construct(public readonly array $groups = ['Default'])
    {
        $this->instance = ++self::$instances;
    }

    public function validate(mixed $value, string $propertyName): ?string
    {
        return 'instance ' . $this->instance;
    }
}

class CountingAssertDto
{
    #[CountingAssert]
    public string $value = 'v';
}

class CallbackCountingDto
{
    public int $calls = 0;

    /** @return array<string, string> */
    #[Callback]
    public function check(): array
    {
        $this->calls++;

        return [];
    }
}
