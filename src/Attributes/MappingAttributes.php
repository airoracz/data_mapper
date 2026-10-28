<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Attributes;

use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

/**
 * Mapping settings of a property or constructor parameter, read from #[MapDateTimeProperty]
 * and #[MapProperty]. The single place for the precedence rules used by the normalizer,
 * denormalizer, merge() and class metadata:
 *
 * - #[MapDateTimeProperty] wins for name, path and arrayOf - even when it leaves them null,
 * - type falls through to #[MapProperty] when #[MapDateTimeProperty] has none, then to the PHP type,
 * - classType comes from #[MapProperty] only; format, timezone and outputFormat from #[MapDateTimeProperty] only.
 *
 * @internal
 */
final class MappingAttributes
{
    private function __construct(
        private readonly string $name,
        private readonly ?ReflectionType $reflectionType,
        private readonly ?MapDateTimeProperty $dateTime,
        private readonly ?MapProperty $property,
    ) {
    }

    public static function of(ReflectionProperty|ReflectionParameter $reflector): self
    {
        $dateTimeAttributes = $reflector->getAttributes(MapDateTimeProperty::class);
        $propertyAttributes = $reflector->getAttributes(MapProperty::class);

        return new self(
            $reflector->getName(),
            $reflector->getType(),
            $dateTimeAttributes !== [] ? $dateTimeAttributes[0]->newInstance() : null,
            $propertyAttributes !== [] ? $propertyAttributes[0]->newInstance() : null,
        );
    }

    public function jsonKey(): string
    {
        if ($this->dateTime !== null) {
            return $this->dateTime->name ?? $this->name;
        }

        return $this->property->name ?? $this->name;
    }

    public function path(): ?string
    {
        if ($this->dateTime !== null) {
            return $this->dateTime->path;
        }

        return $this->property?->path;
    }

    public function typeName(): string
    {
        $attributeType = $this->dateTime->type ?? $this->property?->type;
        if ($attributeType !== null) {
            return $attributeType->value;
        }

        if ($this->reflectionType instanceof ReflectionNamedType) {
            return $this->reflectionType->getName();
        }

        return 'string';
    }

    /**
     * @return class-string|string|null Class name or scalar type name of array elements
     */
    public function arrayOf(): ?string
    {
        $arrayOf = $this->dateTime !== null ? $this->dateTime->arrayOf : $this->property?->arrayOf;

        if ($arrayOf instanceof PropertyType || $arrayOf instanceof ArrayElementType) {
            return $arrayOf->value;
        }

        return $arrayOf;
    }

    /**
     * @return class-string|null
     */
    public function classType(): ?string
    {
        return $this->property?->classType;
    }

    public function format(): ?string
    {
        return $this->dateTime?->format;
    }

    public function timezone(): ?string
    {
        return $this->dateTime?->timezone;
    }

    public function outputFormat(): ?string
    {
        return $this->dateTime?->outputFormat;
    }
}
