<?php

declare(strict_types=1);

namespace Pocta\DataMapper;

use Pocta\DataMapper\Attributes\Ignore;
use Pocta\DataMapper\Attributes\InputOnly;
use Pocta\DataMapper\Attributes\MapDateTimeProperty;
use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Attributes\OutputOnly;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Decides which properties and constructor parameters take part in mapping.
 *
 * - Static properties never do - they are class-level state, not object data.
 * - #[Ignore] excludes a member in both directions, #[OutputOnly] from input,
 *   #[InputOnly] from output. These always win over an opt-in attribute.
 * - In public-only mode, non-public members are mapped only when explicitly
 *   opted in with #[MapProperty] or #[MapDateTimeProperty].
 */
class PropertyVisibilityResolver
{
    /**
     * Whether the property is written to output (normalization).
     */
    public static function isPropertyNormalizable(ReflectionProperty $property, bool $publicPropertiesOnly): bool
    {
        if (self::hasAnyAttribute($property, [Ignore::class, InputOnly::class])) {
            return false;
        }

        return self::isPropertyVisible($property, $publicPropertiesOnly);
    }

    /**
     * Whether the property is hydrated from input (denormalization, merge).
     */
    public static function isPropertyDenormalizable(ReflectionProperty $property, bool $publicPropertiesOnly): bool
    {
        if (self::hasAnyAttribute($property, [Ignore::class, OutputOnly::class])) {
            return false;
        }

        return self::isPropertyVisible($property, $publicPropertiesOnly);
    }

    /**
     * Whether the constructor parameter is hydrated from input.
     * Non-promoted parameters are subject only to #[Ignore]/#[OutputOnly] - they are
     * the constructor's explicit API, not a property backdoor.
     */
    public static function isParameterDenormalizable(ReflectionParameter $parameter, bool $publicPropertiesOnly): bool
    {
        if (self::hasAnyAttribute($parameter, [Ignore::class, OutputOnly::class])) {
            return false;
        }

        if (!$publicPropertiesOnly || !$parameter->isPromoted()) {
            return true;
        }

        $class = $parameter->getDeclaringClass();
        if ($class !== null && $class->getProperty($parameter->getName())->isPublic()) {
            return true;
        }

        return self::hasAnyAttribute($parameter, [MapProperty::class, MapDateTimeProperty::class]);
    }

    private static function isPropertyVisible(ReflectionProperty $property, bool $publicPropertiesOnly): bool
    {
        if ($property->isStatic()) {
            return false;
        }

        if (!$publicPropertiesOnly || $property->isPublic()) {
            return true;
        }

        return self::hasAnyAttribute($property, [MapProperty::class, MapDateTimeProperty::class]);
    }

    /**
     * @param array<class-string> $attributeClasses
     */
    private static function hasAnyAttribute(ReflectionProperty|ReflectionParameter $reflector, array $attributeClasses): bool
    {
        foreach ($attributeClasses as $attributeClass) {
            if (!empty($reflector->getAttributes($attributeClass))) {
                return true;
            }
        }

        return false;
    }
}
