<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Validation;

use Attribute;

/**
 * Validates that the value is a valid UUID (Universally Unique Identifier)
 * Supports versions 1, 3, 4, and 5
 *
 * Example:
 * #[Uuid]
 * public string $id;
 *
 * #[Uuid(version: 4)]
 * public string $uuid; // Only accepts UUID v4
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Uuid implements AssertInterface
{
    private const DEFAULT_MESSAGE = 'Value must be a valid UUID';

    public function __construct(
        public readonly ?int $version = null,
        public readonly string $message = self::DEFAULT_MESSAGE,
        /** @var array<string> */
        public readonly array $groups = ['Default'],
    ) {
        if ($version !== null && !in_array($version, [1, 3, 4, 5], true)) {
            throw new \InvalidArgumentException('UUID version must be 1, 3, 4, or 5');
        }
    }

    public function validate(mixed $value, string $propertyName): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return "Property '{$propertyName}' must be string for UUID validation";
        }

        // Basic UUID format: 8-4-4-4-12 hexadecimal digits
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        if (!preg_match($pattern, $value)) {
            return $this->failure($propertyName);
        }

        // Validate specific version if requested
        if ($this->version !== null) {
            $versionChar = $value[14]; // 15th character (0-indexed) indicates version
            if ((int) $versionChar !== $this->version) {
                return $this->failure($propertyName);
            }
        }

        return null;
    }

    /**
     * Only the default message gets the property name (and version); a custom message is returned as written.
     */
    private function failure(string $propertyName): string
    {
        if ($this->message !== self::DEFAULT_MESSAGE) {
            return $this->message;
        }

        $message = $this->version !== null
            ? str_replace('UUID', "UUID v{$this->version}", $this->message)
            : $this->message;

        return str_replace('Value', "Property '{$propertyName}'", $message);
    }
}
