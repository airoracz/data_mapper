<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Validation\Choice;
use Pocta\DataMapper\Validation\Length;
use Pocta\DataMapper\Validation\NotBlank;
use Pocta\DataMapper\Validation\Range;
use Pocta\DataMapper\Validation\Validator;

/**
 * Custom validation messages may use {{ name }}, {{ value }} and {{ <option> }} placeholders.
 */
class ValidationMessagePlaceholdersTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function errors(object $object): array
    {
        return (new Validator())->validate($object, throw: false);
    }

    public function testNameValueAndOptionPlaceholders(): void
    {
        $dto = new PlaceholderDto();
        $dto->code = 'ab';

        $this->assertSame(
            'Field code must have at least 3 characters, got "ab"',
            $this->errors($dto)['code']
        );
    }

    public function testRangePlaceholders(): void
    {
        $dto = new PlaceholderDto();
        $dto->qty = 50;

        $this->assertSame('qty must be between 1 and 10 (50 given)', $this->errors($dto)['qty']);
    }

    public function testArrayOptionIsJoined(): void
    {
        $dto = new PlaceholderDto();
        $dto->color = 'pink';

        $this->assertSame('Choose one of: red, green', $this->errors($dto)['color']);
    }

    public function testUnknownPlaceholderIsKept(): void
    {
        $dto = new PlaceholderDto();
        $dto->note = '';

        $this->assertSame('Note {{ unknown }} is required', $this->errors($dto)['note']);
    }

    public function testDefaultMessagesAreUnchanged(): void
    {
        $dto = new DefaultMessageDto();

        $this->assertSame(
            ['code' => "Property 'code' must be at least 3 characters long"],
            $this->errors($dto)
        );
    }
}

class PlaceholderDto
{
    #[Length(min: 3, message: 'Field {{ name }} must have at least {{ min }} characters, got "{{ value }}"')]
    public string $code = 'abc';

    #[Range(min: 1, max: 10, message: '{{ name }} must be between {{ min }} and {{ max }} ({{ value }} given)')]
    public int $qty = 5;

    #[Choice(choices: ['red', 'green'], message: 'Choose one of: {{ choices }}')]
    public string $color = 'red';

    #[NotBlank(message: 'Note {{ unknown }} is required')]
    public string $note = 'x';
}

class DefaultMessageDto
{
    #[Length(min: 3)]
    public string $code = 'ab';
}
