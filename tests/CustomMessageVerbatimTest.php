<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Validation\Iban;
use Pocta\DataMapper\Validation\Regex;
use Pocta\DataMapper\Validation\Uuid;

/**
 * Custom messages are returned as written; only the default messages get the property name inserted.
 */
class CustomMessageVerbatimTest extends TestCase
{
    // --- Defaults unchanged ---

    public function testRegexDefaultMessage(): void
    {
        $this->assertSame("Property 'code' does not match the required pattern", (new Regex('/^\d+$/'))->validate('ab', 'code'));
    }

    public function testUuidDefaultMessages(): void
    {
        $this->assertSame("Property 'id' must be a valid UUID", (new Uuid())->validate('x', 'id'));
        $this->assertSame("Property 'id' must be a valid UUID v4", (new Uuid(4))->validate('x', 'id'));
        $this->assertSame(
            "Property 'id' must be a valid UUID v4",
            (new Uuid(4))->validate('123e4567-e89b-12d3-a456-426614174000', 'id')
        );
    }

    public function testIbanDefaultMessage(): void
    {
        $this->assertSame("Property 'iban' must be a valid IBAN", (new Iban())->validate('XX00', 'iban'));
    }

    // --- Custom messages verbatim ---

    public function testRegexCustomMessageIsVerbatim(): void
    {
        $this->assertSame('Value of the code is invalid', (new Regex('/^\d+$/', message: 'Value of the code is invalid'))->validate('ab', 'code'));
    }

    public function testUuidCustomMessageIsVerbatimForBothChecks(): void
    {
        $uuid = new Uuid(4, message: 'Value must be our UUID');

        $this->assertSame('Value must be our UUID', $uuid->validate('x', 'id'));
        $this->assertSame('Value must be our UUID', $uuid->validate('123e4567-e89b-12d3-a456-426614174000', 'id'));
    }

    public function testIbanCustomMessageIsVerbatim(): void
    {
        $this->assertSame('Value is not a bank account', (new Iban(message: 'Value is not a bank account'))->validate('XX00', 'iban'));
    }
}
