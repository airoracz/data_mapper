<?php

declare(strict_types=1);

namespace Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Validation\Callback;
use Pocta\DataMapper\Validation\Validator;

/**
 * A #[Callback] validation method must return an array of errors (or null). Any other value was
 * silently ignored, hiding a broken validation rule.
 */
class CallbackReturnValueTest extends TestCase
{
    public function testArrayResultReportsErrors(): void
    {
        $errors = (new Validator())->validate(new CallbackReturnsArray(), throw: false);

        $this->assertSame(['field' => 'is wrong'], $errors);
    }

    public function testNullAndEmptyArrayMeanValid(): void
    {
        $this->assertSame([], (new Validator())->validate(new CallbackReturnsNullOrEmpty(), throw: false));
    }

    public function testStringResultIsAConfigurationError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('CallbackReturnsString::check()');

        (new Validator())->validate(new CallbackReturnsString(), throw: false);
    }

    public function testBoolResultIsAConfigurationError(): void
    {
        $this->expectException(LogicException::class);

        (new Validator())->validate(new CallbackReturnsBool(), throw: false);
    }
}

class CallbackReturnsArray
{
    /** @return array<string, string> */
    #[Callback]
    public function check(): array
    {
        return ['field' => 'is wrong'];
    }
}

class CallbackReturnsNullOrEmpty
{
    /** @return array<string, string>|null */
    #[Callback]
    public function nothing(): ?array
    {
        return null;
    }

    /** @return array<string, string> */
    #[Callback]
    public function empty(): array
    {
        return [];
    }
}

class CallbackReturnsString
{
    #[Callback]
    public function check(): string
    {
        return 'is wrong';
    }
}

class CallbackReturnsBool
{
    #[Callback]
    public function check(): bool
    {
        return false;
    }
}
