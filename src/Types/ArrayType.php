<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Types;

use InvalidArgumentException;
use Pocta\DataMapper\Denormalizer\Denormalizer;
use Pocta\DataMapper\Exceptions\ValidationException;
use Pocta\DataMapper\Normalizer\Normalizer;
use Pocta\DataMapper\Types\TypeResolver;

/**
 * Type handler for arrays of objects or scalars
 */
class ArrayType implements TypeInterface
{
    private ?Denormalizer $denormalizer = null;
    private ?Normalizer $normalizer = null;
    private ?TypeInterface $elementType = null;

    /**
     * @param class-string|null $elementClassName Class name for array elements (for object arrays).
     * @param TypeInterface|null $elementType Type handler for array elements (for scalar arrays).
     * @param bool $strictMode Enable strict mode for nested denormalizations.
     * @param TypeResolver|null $typeResolver Type resolver for nested denormalizations.
     */
    public function __construct(
        private readonly ?string $elementClassName = null,
        ?TypeInterface $elementType = null,
        private readonly bool $strictMode = false,
        private readonly ?TypeResolver $typeResolver = null
    ) {
        if ($this->elementClassName !== null) {
            if (!class_exists($this->elementClassName)) {
                throw new InvalidArgumentException(
                    "Class '{$this->elementClassName}' does not exist"
                );
            }
            // Use shared denormalizer from TypeResolver if available
            if ($this->typeResolver !== null) {
                $this->denormalizer = $this->typeResolver->getSharedDenormalizer();
            } else {
                $this->denormalizer = new Denormalizer();
                $this->denormalizer->setStrictMode($this->strictMode);
            }
            $this->normalizer = new Normalizer($this->typeResolver);
        }

        $this->elementType = $elementType;
    }

    public function getName(): string
    {
        return 'array';
    }

    public function getAliases(): array
    {
        return ['array'];
    }

    /**
     * @param mixed $value The raw value to denormalize
     * @param string $fieldName The name of the field (for error messages)
     * @param bool $isNullable Whether the field accepts null values
     * @return array<int|string, mixed>|null The denormalized array
     */
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

        // Value must be an array
        if (!is_array($value)) {
            throw new InvalidArgumentException(
                "Field '{$fieldName}' must be an array, got: " . get_debug_type($value)
            );
        }

        // If no element class or type is specified, return as-is
        if ($this->elementClassName === null && $this->elementType === null) {
            return $value;
        }

        $result = [];
        $collectedErrors = [];

        // Denormalize each element
        $previousPrefix = $this->denormalizer !== null ? $this->denormalizer->getPathPrefix() : '';

        // Denormalize each element
        foreach ($value as $key => $item) {
            if ($this->elementClassName !== null) {
                // Object array
                assert($this->denormalizer !== null);
                if ($item === null) {
                    $result[$key] = null;
                } elseif (is_array($item)) {
                    try {
                        // Set path prefix for nested denormalization
                        $this->denormalizer->setPathPrefix("{$fieldName}[{$key}]");
                        /** @var array<string, mixed> $item */
                        $result[$key] = $this->denormalizer->denormalize($item, $this->elementClassName);
                    } catch (ValidationException $e) {
                        // Errors already have full path from denormalizer
                        foreach ($e->getErrors() as $errorField => $errorMessage) {
                            $collectedErrors[$errorField] = $errorMessage;
                        }
                        $result[$key] = null;
                    } finally {
                        // Restore previous path prefix (not reset to '') to preserve parent context
                        $this->denormalizer->setPathPrefix($previousPrefix);
                    }
                } elseif ($item instanceof $this->elementClassName) {
                    $result[$key] = $item;
                } else {
                    $collectedErrors["{$fieldName}[{$key}]"] =
                        "Array element at path '{$fieldName}[{$key}]' must be an array or instance of {$this->elementClassName}, got: "
                        . get_debug_type($item);
                    $result[$key] = null;
                }
            } elseif ($this->elementType !== null) {
                // Scalar array with type
                try {
                    $result[$key] = $this->elementType->denormalize($item, "{$fieldName}[{$key}]", true);
                } catch (InvalidArgumentException $e) {
                    $collectedErrors["{$fieldName}[{$key}]"] = $e->getMessage();
                    $result[$key] = null;
                }
            }
        }

        // If we collected any errors, throw ValidationException
        if (!empty($collectedErrors)) {
            throw new ValidationException($collectedErrors);
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed>|null $value The array to normalize
     * @return array<int|string, mixed>|null The normalized array
     */
    public function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // @phpstan-ignore-next-line function.alreadyNarrowedType (runtime safety check)
        if (!is_array($value)) {
            throw new InvalidArgumentException(
                "Expected array, got: " . get_debug_type($value)
            );
        }

        // If no element class or type is specified, return as-is
        if ($this->elementClassName === null && $this->elementType === null) {
            return $value;
        }

        if ($this->normalizer !== null) {
            $this->typeResolver?->configureNormalizer($this->normalizer);
        }

        $result = [];

        // Normalize each element
        foreach ($value as $key => $item) {
            if ($item === null) {
                $result[$key] = null;
            } elseif ($this->elementClassName !== null && is_object($item)) {
                // Object array
                assert($this->normalizer !== null);
                $result[$key] = $this->normalizer->normalize($item);
            } elseif ($this->elementType !== null) {
                // Scalar array with type
                $result[$key] = $this->elementType->normalize($item);
            } else {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
