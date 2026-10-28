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
        $reflection = new ReflectionClass($object);
        $errors = [];

        foreach ($reflection->getProperties() as $property) {
            $propertyErrors = $this->validateProperty($property, $object, $pathPrefix, $activeGroups);
            $errors = array_merge($errors, $propertyErrors);
        }

        // Validate methods with #[Callback] attribute
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methodErrors = $this->validateCallbackMethod($method, $object, $pathPrefix, $activeGroups);
            $errors = array_merge($errors, $methodErrors);
        }

        return $errors;
    }

    /**
     * Validate a single property
     *
     * @param array<string> $activeGroups Active validation groups
     * @return array<string, string> Array of path => error message
     */
    private function validateProperty(ReflectionProperty $property, object $object, string $pathPrefix, array $activeGroups): array
    {
        $errors = [];

        if (!empty($property->getAttributes(SkipValidation::class))) {
            return $errors;
        }

        if (!$property->isInitialized($object)) {
            if ($this->hasActiveValidationAttributes($property, $activeGroups)) {
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

        foreach ($property->getAttributes() as $attribute) {
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
                    $errors[$fullPath] = $error;
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
                    $errors[$fullPath] = $error;
                    $hasError = true;
                }
            }
        }

        return $errors;
    }

    /**
     * Validate a method with #[Callback] attribute
     *
     * @param array<string> $activeGroups Active validation groups
     * @return array<string, string> Array of path => error message
     */
    private function validateCallbackMethod(ReflectionMethod $method, object $object, string $pathPrefix, array $activeGroups): array
    {
        $errors = [];

        foreach ($method->getAttributes(Callback::class) as $attribute) {
            $instance = $attribute->newInstance();

            if (!$this->matchesGroups($instance, $activeGroups)) {
                continue;
            }

            /** @var mixed $result */
            $result = $method->invoke($object);

            if ($result === null || $result === []) {
                continue;
            }

            if (is_array($result)) {
                /** @var array<string, string> $result */
                foreach ($result as $key => $message) {
                    $fullPath = $pathPrefix !== '' ? $pathPrefix . '.' . $key : $key;
                    $errors[$fullPath] = $message;
                }
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
     * @param array<string> $activeGroups Active validation groups
     */
    private function hasActiveValidationAttributes(ReflectionProperty $property, array $activeGroups): bool
    {
        foreach ($property->getAttributes() as $attribute) {
            $instance = $attribute->newInstance();

            if ($instance instanceof AssertInterface && $this->matchesGroups($instance, $activeGroups)) {
                return true;
            }
        }

        return false;
    }
}
