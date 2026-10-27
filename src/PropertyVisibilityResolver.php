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

        return self::isPropertyVisible($property, $publicPropertiesOnly, forWriting: false);
    }

    /**
     * Whether the property is hydrated from input (denormalization, merge).
     */
    public static function isPropertyDenormalizable(ReflectionProperty $property, bool $publicPropertiesOnly): bool
    {
        if (self::hasAnyAttribute($property, [Ignore::class, OutputOnly::class])) {
            return false;
        }

        return self::isPropertyVisible($property, $publicPropertiesOnly, forWriting: true);
    }

    /**
     * Whether the constructor parameter is hydrated from input.
     *
     * #[Ignore]/#[OutputOnly] on the parameter or on the same-named property exclude it -
     * otherwise a plain parameter assigned to that property in the constructor would
     * bypass the attribute. Visibility rules apply only to promoted parameters; plain
     * parameters are the constructor's explicit API.
     */
    public static function isParameterDenormalizable(ReflectionParameter $parameter, bool $publicPropertiesOnly): bool
    {
        if (self::hasAnyAttribute($parameter, [Ignore::class, OutputOnly::class])) {
            return false;
        }

        $class = $parameter->getDeclaringClass();
        $property = $class !== null && $class->hasProperty($parameter->getName())
            ? $class->getProperty($parameter->getName())
            : null;

        if ($property !== null && self::hasAnyAttribute($property, [Ignore::class, OutputOnly::class])) {
            return false;
        }

        if (!$publicPropertiesOnly || !$parameter->isPromoted()) {
            return true;
        }

        if ($property !== null && self::isPubliclyWritable($property)) {
            return true;
        }

        return self::hasAnyAttribute($parameter, [MapProperty::class, MapDateTimeProperty::class]);
    }

    private static function isPropertyVisible(ReflectionProperty $property, bool $publicPropertiesOnly, bool $forWriting): bool
    {
        if ($property->isStatic()) {
            return false;
        }

        if (!$publicPropertiesOnly) {
            return true;
        }

        if ($forWriting ? self::isPubliclyWritable($property) : $property->isPublic()) {
            return true;
        }

        return self::hasAnyAttribute($property, [MapProperty::class, MapDateTimeProperty::class]);
    }

    /**
     * Public for writing - excludes PHP 8.4 asymmetric visibility (`public private(set)`,
     * `public protected(set)`), where isPublic() reports only read visibility.
     * Readonly properties are implicitly protected(set) in PHP 8.4 but stay writable here,
     * as in earlier PHP versions (initialization via reflection is their intended use).
     */
    private static function isPubliclyWritable(ReflectionProperty $property): bool
    {
        if (!$property->isPublic()) {
            return false;
        }

        if ($property->isReadOnly()) {
            return true;
        }

        // PHP < 8.4 has no asymmetric visibility
        if (!method_exists($property, 'isPrivateSet') || !method_exists($property, 'isProtectedSet')) {
            return true;
        }

        return !$property->isPrivateSet() && !$property->isProtectedSet();
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
