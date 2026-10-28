<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Types;

use UnitEnum;
use InvalidArgumentException;

/**
 * Type handler for UnitEnum (enums without scalar values)
 *
 * @template T of UnitEnum
 */
class UnitEnumType implements TypeInterface
{
    /**
     * @param class-string<T> $enumClass
     */
    public function __construct(
        private readonly string $enumClass
    ) {
        if (!enum_exists($this->enumClass)) {
            throw new InvalidArgumentException(
                "Class '{$this->enumClass}' is not an enum"
            );
        }

        if (!is_subclass_of($this->enumClass, UnitEnum::class)) {
            throw new InvalidArgumentException(
                "Enum '{$this->enumClass}' must be a UnitEnum"
            );
        }
    }

    public function getName(): string
    {
        return $this->enumClass;
    }

    public function getAliases(): array
    {
        return [$this->enumClass];
    }

    /**
     * @param mixed $value The raw value to denormalize (case name as string)
     * @param string $fieldName The name of the field (for error messages)
     * @param bool $isNullable Whether the field accepts null values
     * @return T|null The enum instance
     */
    /** @var array<string, UnitEnum>|null */
    private ?array $casesByName = null;

    public function denormalize(mixed $value, string $fieldName, bool $isNullable): mixed
    {
        if ($value === null) {
            if ($isNullable) {
                return null;
            }
            throw new InvalidArgumentException(
                "Field '{$fieldName}' does not accept null values"
            );
        }

        // If value is already an instance of the enum, return it
        if ($value instanceof $this->enumClass) {
            return $value;
        }

        // Convert value to string (case name)
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException(
                "Field '{$fieldName}' must be a string or integer representing the enum case name, got: "
                . get_debug_type($value)
            );
        }

        // Find the matching case (name => case map built once per type handler)
        if ($this->casesByName === null) {
            $this->casesByName = [];
            foreach (($this->enumClass)::cases() as $case) {
                $this->casesByName[$case->name] = $case;
            }
        }

        $caseName = (string) $value;
        if (isset($this->casesByName[$caseName])) {
            return $this->casesByName[$caseName];
        }

        // No matching case found
        $validCases = array_map(
            fn(UnitEnum $case) => $case->name,
            ($this->enumClass)::cases()
        );
        throw new InvalidArgumentException(
            "Invalid value for field '{$fieldName}'. Expected one of: "
            . implode(', ', $validCases)
            . ", got: {$caseName}"
        );
    }

    /**
     * @param T|null $value The enum instance to normalize
     * @return string|null The case name
     */
    public function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // @phpstan-ignore-next-line instanceof.alwaysTrue (runtime safety check)
        if (!$value instanceof UnitEnum) {
            throw new InvalidArgumentException(
                "Expected instance of UnitEnum, got: " . get_debug_type($value)
            );
        }

        return $value->name;
    }
}
