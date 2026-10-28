<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Validation;

use Pocta\DataMapper\Exceptions\ValidationException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Validator for objects with Assert attributes
 */
class Validator
{
    /**
     * Objects on the current #[Valid] recursion path (spl_object_id => true).
     * Path-scoped, not global: an object shared by two branches is validated on each path,
     * only a cycle back to an ancestor is skipped.
     *
     * @var array<int, true>
     */
    private array $validationPath = [];

    /**
     * Per-class reflection: properties with their (not yet instantiated) attributes and methods
     * carrying #[Callback]. Attribute instances are still created on every validation - validators
     * may depend on construction time (e.g. DateTimeLessThan('now')).
     *
     * @var array<string, array{
     *     properties: list<array{0: ReflectionProperty, 1: bool, 2: list<\ReflectionAttribute<object>>}>,
     *     callbacks: list<array{0: ReflectionMethod, 1: list<\ReflectionAttribute<Callback>>}>
     * }>
     */
    private array $classCache = [];

    public function __construct(
        private readonly ?ValidatorResolverInterface $validatorResolver = null,
    ) {
    }

    /**
     * Validate an object against its Assert attributes
     *
     * @param object $object Object to validate
     * @param bool $throw Whether to throw exception on validation failure
     * @param array<string>|null $groups Validation groups to apply (null = auto-detect)
     * @return array<string, string> Array of property path => error message (empty if valid)
     * @throws ValidationException If validation fails and $throw = true
     */
    public function validate(object $object, bool $throw = true, ?array $groups = null): array
    {
        $activeGroups = $this->resolveGroups($object, $groups);
        $errors = $this->validateObject($object, '', $activeGroups);

        if (!empty($errors) && $throw) {
            throw new ValidationException($errors);
        }

        return $errors;
    }

    /**
     * Check if object is valid (has no validation errors)
     */
    public function isValid(object $object): bool
    {
        $errors = $this->validate($object, throw: false);
        return empty($errors);
    }

    /**
     * Get validation errors without throwing exception
     *
     * @return array<string, string>
     */
    public function getErrors(object $object): array
    {
        return $this->validate($object, throw: false);
    }

    /**
     * Resolve which validation groups to use
     *
     * @param object $object Object being validated
     * @param array<string>|null $groups Explicitly provided groups
     * @return array<string> Active groups
     */
    private function resolveGroups(object $object, ?array $groups): array
    {
        if ($groups !== null) {
            return $groups;
        }

        if ($object instanceof GroupSequenceProviderInterface) {
            return $object->getGroupSequence();
        }

        return ['Default'];
    }

    /**
     * Validate an object and return errors with path prefixes
     *
     * @param object $object Object to validate
     * @param string $pathPrefix Path prefix for nested error keys
     * @param array<string> $activeGroups Active validation groups
     * @return array<string, string> Flat array of path => error message
     */
    private function validateObject(object $object, string $pathPrefix, array $activeGroups): array
    {
        $objectId = spl_object_id($object);
        if (isset($this->validationPath[$objectId])) {
            // Cycle back to an ancestor - it is already being validated
            return [];
        }

        $this->validationPath[$objectId] = true;
        try {
            return $this->validateObjectMembers($object, $pathPrefix, $activeGroups);
        } finally {
            unset($this->validationPath[$objectId]);
        }
    }

    /**
     * @param array<string> $activeGroups Active validation groups
     * @return array<string, string> Flat array of path => error message
     */
    private function validateObjectMembers(object $object, string $pathPrefix, array $activeGroups): array
    {
        $classInfo = $this->classCache[$object::class] ??= $this->buildClassInfo($object);
        $errors = [];

        foreach ($classInfo['properties'] as [$property, $skip, $attributes]) {
            // No attributes (or #[SkipValidation]) = no errors, initialized or not
            if ($skip || $attributes === []) {
                continue;
            }
            $propertyErrors = $this->validateProperty($property, $attributes, $object, $pathPrefix, $activeGroups);
            if ($propertyErrors !== []) {
                $errors = array_merge($errors, $propertyErrors);
            }
        }

        // Validate methods with #[Callback] attribute
        foreach ($classInfo['callbacks'] as [$method, $callbackAttributes]) {
            $methodErrors = $this->validateCallbackMethod($method, $callbackAttributes, $object, $pathPrefix, $activeGroups);
            if ($methodErrors !== []) {
                $errors = array_merge($errors, $methodErrors);
            }
        }

        return $errors;
    }

    /**
     * @return array{
     *     properties: list<array{0: ReflectionProperty, 1: bool, 2: list<\ReflectionAttribute<object>>}>,
     *     callbacks: list<array{0: ReflectionMethod, 1: list<\ReflectionAttribute<Callback>>}>
     * }
     */
    private function buildClassInfo(object $object): array
    {
        $reflection = new ReflectionClass($object);
        $properties = [];
        foreach ($reflection->getProperties() as $property) {
            $properties[] = [
                $property,
                $property->getAttributes(SkipValidation::class) !== [],
                $property->getAttributes(),
            ];
        }

        $callbacks = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $callbackAttributes = $method->getAttributes(Callback::class);
            if ($callbackAttributes !== []) {
                $callbacks[] = [$method, $callbackAttributes];
            }
        }

        return ['properties' => $properties, 'callbacks' => $callbacks];
    }

    /**
     * Validate a single property
     *
     * @param list<\ReflectionAttribute<object>> $attributes Attributes of the property (cached reflection)
     * @param array<string> $activeGroups Active validation groups
     * @return array<string, string> Array of path => error message
     */
    private function validateProperty(ReflectionProperty $property, array $attributes, object $object, string $pathPrefix, array $activeGroups): array
    {
        $errors = [];

        if (!$property->isInitialized($object)) {
            if ($this->hasActiveValidationAttributes($attributes, $activeGroups)) {
                $fullPath = $pathPrefix !== ''
                    ? $pathPrefix . '.' . $property->getName()
                    : $property->getName();
                $errors[$fullPath] = 'This field is required.';
            }

            return $errors;
        }

        $value = $property->getValue($object);
        $propertyName = $property->getName();
        $fullPath = $pathPrefix !== '' ? $pathPrefix . '.' . $propertyName : $propertyName;

        // Only the first failing assert per property is reported, but #[Valid] always runs -
        // otherwise nested errors would depend on attribute order
        $hasError = false;

        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();

            if ($instance instanceof Valid) {
                // Check groups on the Valid attribute itself
                if (!$this->matchesGroups($instance, $activeGroups)) {
                    continue;
                }

                // Recursive validation of nested object
                if (is_object($value)) {
                    $nestedErrors = $this->validateObject($value, $fullPath, $activeGroups);
                    $errors = array_merge($errors, $nestedErrors);
                }
                // Recursive validation of array of objects
                if (is_array($value)) {
                    foreach ($value as $index => $item) {
                        if (is_object($item)) {
                            $nestedErrors = $this->validateObject($item, $fullPath . '[' . $index . ']', $activeGroups);
                            $errors = array_merge($errors, $nestedErrors);
                        }
                    }
                }
                continue;
            }

            if ($hasError) {
                continue;
            }

            if ($instance instanceof ConstraintInterface) {
                if (!$this->matchesGroups($instance, $activeGroups)) {
                    continue;
                }

                $validatorClass = $instance->validatedBy();
                $validator = $this->validatorResolver !== null
                    ? $this->validatorResolver->resolve($validatorClass)
                    : new $validatorClass();

                $error = $validator->validate($value, $instance, $object);
                if ($error !== null) {
                    $errors[$fullPath] = $this->interpolate($error, $instance, $propertyName, $value);
                    $hasError = true;
                }
                continue;
            }

            if ($instance instanceof AssertInterface) {
                // Check groups filtering
                if (!$this->matchesGroups($instance, $activeGroups)) {
                    continue;
                }

                $error = $instance->validate($value, $propertyName);
                if ($error !== null) {
                    $errors[$fullPath] = $this->interpolate($error, $instance, $propertyName, $value);
                    $hasError = true;
                }
            }
        }

        return $errors;
    }

    /**
     * Validate a method with #[Callback] attribute
     *
     * @param list<\ReflectionAttribute<Callback>> $callbackAttributes
     * @param array<string> $activeGroups Active validation groups
     * @return array<string, string> Array of path => error message
     */
    private function validateCallbackMethod(ReflectionMethod $method, array $callbackAttributes, object $object, string $pathPrefix, array $activeGroups): array
    {
        $errors = [];

        foreach ($callbackAttributes as $attribute) {
            $instance = $attribute->newInstance();

            if (!$this->matchesGroups($instance, $activeGroups)) {
                continue;
            }

            /** @var mixed $result */
            $result = $method->invoke($object);

            if ($result === null || $result === []) {
                continue;
            }

            if (!is_array($result)) {
                throw new \LogicException(sprintf(
                    '#[Callback] method %s::%s() must return an array of errors or null, %s returned',
                    $method->getDeclaringClass()->getShortName(),
                    $method->getName(),
                    get_debug_type($result)
                ));
            }

            /** @var array<string, string> $result */
            foreach ($result as $key => $message) {
                $fullPath = $pathPrefix !== '' ? $pathPrefix . '.' . $key : $key;
                $errors[$fullPath] = $message;
            }
        }

        return $errors;
    }

    /**
     * Check if a constraint's groups match the active groups
     *
     * @param object $constraint The constraint instance
     * @param array<string> $activeGroups Active validation groups
     * @return bool True if the constraint should be applied
     */
    private function matchesGroups(object $constraint, array $activeGroups): bool
    {
        if (!property_exists($constraint, 'groups')) {
            // Constraints without groups property are always applied
            return true;
        }

        /** @var array<string> $constraintGroups */
        $constraintGroups = $constraint->groups;

        return !empty(array_intersect($constraintGroups, $activeGroups));
    }

    /**
     * Check if a property has any validation attributes matching the active groups
     *
     * @param list<\ReflectionAttribute<object>> $attributes
     * @param array<string> $activeGroups Active validation groups
     */
    private function hasActiveValidationAttributes(array $attributes, array $activeGroups): bool
    {
        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();

            if ($instance instanceof AssertInterface && $this->matchesGroups($instance, $activeGroups)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replaces {{ name }}, {{ value }} and {{ <option> }} placeholders in a message. Options are the
     * public scalar (or list-of-scalar) properties of the constraint, e.g. {{ min }}, {{ max }},
     * {{ choices }}. {{ value }} is always the validated value. Unknown placeholders are kept;
     * messages without "{{" are returned unchanged.
     */
    private function interpolate(string $message, object $constraint, string $propertyName, mixed $value): string
    {
        if (!str_contains($message, '{{')) {
            return $message;
        }

        $params = [];
        foreach (get_object_vars($constraint) as $option => $optionValue) {
            $params[$option] = $this->stringify($optionValue);
        }
        $params['name'] = $propertyName;
        $params['value'] = $this->stringify($value);

        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/',
            fn(array $match): string => $params[$match[1]] ?? $match[0],
            $message
        );
    }

    private function stringify(mixed $value): ?string
    {
        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                if (!is_scalar($item)) {
                    return null;
                }
                $items[] = is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;
            }

            return implode(', ', $items);
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \Stringable => (string) $value,
            default => null,
        };
    }
}
