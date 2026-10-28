<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Validation;

use Attribute;

/**
 * Validates that the value is a valid IBAN (International Bank Account Number)
 *
 * Example:
 * #[Iban]
 * public string $bankAccount;
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Iban implements AssertInterface
{
    private const DEFAULT_MESSAGE = 'Value must be a valid IBAN';

    public function __construct(
        public readonly string $message = self::DEFAULT_MESSAGE,
        /** @var array<string> */
        public readonly array $groups = ['Default'],
    ) {
    }

    public function validate(mixed $value, string $propertyName): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return "Property '{$propertyName}' must be string for IBAN validation";
        }

        // Remove spaces and convert to uppercase
        $iban = strtoupper(str_replace(' ', '', $value));

        // IBAN must be between 15 and 34 characters
        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return $this->failure($propertyName);
        }

        // Must start with 2 letters (country code) followed by 2 digits (check digits)
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban)) {
            return $this->failure($propertyName);
        }

        // Move first 4 characters to the end
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);

        // Replace letters with numbers (A=10, B=11, ..., Z=35)
        $numericIban = '';
        for ($i = 0; $i < strlen($rearranged); $i++) {
            $char = $rearranged[$i];
            if (ctype_alpha($char)) {
                $numericIban .= (string) (ord($char) - 55);
            } else {
                $numericIban .= $char;
            }
        }

        // Calculate modulo 97 using bcmod for large numbers
        $isValid = false;
        if (function_exists('bcmod')) {
            $isValid = bcmod($numericIban, '97') === '1';
        } else {
            $isValid = $this->mod97($numericIban) === 1;
        }

        if (!$isValid) {
            return $this->failure($propertyName);
        }

        return null;
    }

    /**
     * Calculate modulo 97 for large numbers without bcmath
     */
    private function mod97(string $number): int
    {
        $remainder = 0;
        for ($i = 0; $i < strlen($number); $i++) {
            $remainder = ($remainder * 10 + (int) $number[$i]) % 97;
        }
        return $remainder;
    }

    /**
     * Only the default message gets the property name; a custom message is returned as written.
     */
    private function failure(string $propertyName): string
    {
        return $this->message === self::DEFAULT_MESSAGE
            ? str_replace('Value', "Property '{$propertyName}'", $this->message)
            : $this->message;
    }
}
