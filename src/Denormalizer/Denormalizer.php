<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Denormalizer;

use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Attributes\MapDateTimeProperty;
use Pocta\DataMapper\Attributes\DiscriminatorMap;
use Pocta\DataMapper\Exceptions\ValidationException;
use Pocta\DataMapper\Types\TypeResolver;
use Pocta\DataMapper\PropertyPathResolver;
use Pocta\DataMapper\PropertyVisibilityResolver;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionNamedType;
use InvalidArgumentException;

class Denormalizer
{
    private TypeResolver $typeResolver;

    /** @var array<string, string> */
    private array $errors = [];

    private bool $strictMode = false;

    private bool $throwOnMissingData = true;

    private bool $publicPropertiesOnly = false;

    /**
     * Per-class reflection, resolved once. Reflector objects are kept alive here, so their
     * spl_object_id() is a stable key for the per-field caches below.
     *
     * @var array<string, array{
     *     reflection: ReflectionClass<object>,
     *     constructor: ?\ReflectionMethod,
     *     parameters: list<ReflectionParameter>,
     *     parameterNames: list<string>,
     *     properties: list<ReflectionProperty>,
     *     denormalizable: array{0: list<ReflectionProperty>, 1: list<ReflectionProperty>},
     *     discriminator: ?DiscriminatorMap
     * }>
     */
    private array $classCache = [];

    /**
     * Attribute-derived mapping settings per property/parameter (keyed by reflector object id).
     *
     * @var array<int, array{
     *     jsonKey: string,
     *     path: ?string,
     *     typeName: string,
     *     format: ?string,
     *     timezone: ?string,
     *     arrayOf: ?string,
     *     classType: ?class-string,
     *     hasHydrator: bool
     * }>
     */
    private array $fieldCache = [];

    /**
     * Filter instances per property/parameter, built lazily on first use - exactly where they were
     * instantiated before, so a misconfigured filter still throws only when its value is processed.
     *
     * @var array<int, \Pocta\DataMapper\Attributes\Filters\FilterChain>
     */
    private array $filterCache = [];

    /** @var array<int, \Pocta\DataMapper\Attributes\MapPropertyWithFunction> */
    private array $hydratorCache = [];

    /** @var array<int, array{0: bool, 1: bool}> Parameter denormalizability per public-only setting */
    private array $parameterDenormalizableCache = [];

    /** @var array<string, array<int, array<string, true>>> Strict-mode known keys per class and public-only setting */
    private array $knownKeysCache = [];

    /**
     * Context stack of payloads for nested denormalization levels.
     * Bottom (index 0) = root payload; Top (end) = current payload.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $contextStack = [];

    /** Whether this instance owns the root payload in TypeResolver. */
    private bool $ownsRootPayload = false;

    /**
     * Stack of class names with depths for cycle detection.
     * Format: [className => depth] where depth is the nesting level.
     *
     * @var array<string, int>
     */
    private array $classDepthMap = [];

    /**
     * Path prefix for nested denormalization (e.g., "addresses[0]" when denormalizing array element).
     * Used to provide better error messages with full path.
     */
    private string $pathPrefix = '';

    /**
     * Set the path prefix for error messages.
     *
     * @param string $prefix
     */
    public function setPathPrefix(string $prefix): void
    {
        $this->pathPrefix = $prefix;
    }

    /**
     * Get the path prefix.
     *
     * @return string
     */
    public function getPathPrefix(): string
    {
        return $this->pathPrefix;
    }

    /**
     * Build full field path for error messages.
     *
     * @param string $fieldName
     *
     * @return string
     */
    private function buildFullFieldPath(string $fieldName): string
    {
        if ($this->pathPrefix === '') {
            return $fieldName;
        }
        return $this->pathPrefix . '.' . $fieldName;
    }

    public function __construct(
        ?TypeResolver $typeResolver = null,
        /** @phpstan-ignore-next-line property.onlyWritten */
        private ?\Pocta\DataMapper\Cache\ClassMetadataFactory $metadataFactory = null
    ) {
        $this->typeResolver = $typeResolver ?? new TypeResolver();
    }

    /**
     * Enable or disable strict mode.
     */
    public function setStrictMode(bool $strictMode): void
    {
        $this->strictMode = $strictMode;
    }

    /**
     * Check if strict mode is enabled.
     */
    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }

    /**
     * Enable or disable throwing on missing data.
     */
    public function setThrowOnMissingData(bool $throwOnMissingData): void
    {
        $this->throwOnMissingData = $throwOnMissingData;
    }

    /**
     * Check if throwing on missing data is enabled.
     */
    public function isThrowOnMissingData(): bool
    {
        return $this->throwOnMissingData;
    }

    /**
     * Enable or disable mapping of public properties only.
     */
    public function setPublicPropertiesOnly(bool $publicPropertiesOnly): void
    {
        $this->publicPropertiesOnly = $publicPropertiesOnly;
    }

    /**
     * Check if only public properties are mapped.
     */
    public function isPublicPropertiesOnly(): bool
    {
        return $this->publicPropertiesOnly;
    }

    /**
     * Converts an associative array to an object.
     *
     * @template T of object
     *
     * @param array<string, mixed> $data
     * @param class-string<T> $className
     *
     * @return T
     *
     * @throws ValidationException
     * @throws \Pocta\DataMapper\Exceptions\CircularReferenceException
     */
    public function denormalize(array $data, string $className): object
    {
        // Reset errors only at the outermost call
        if (empty($this->contextStack)) {
            $this->errors = [];
        }

        // Snapshot errors BEFORE this call. The shared denormalizer instance is
        // reused for nested ObjectType / ArrayType denormalizations, so $this->errors
        // may carry keys produced by a parent invocation. Without this snapshot we
        // would re-throw parent errors as if they happened inside this call, which
        // then get caught in setPropertyValue() / denormalizeValue() and turn into
        // null being assigned to a non-nullable property (PHP TypeError).
        $errorsBeforeCall = $this->errors;

        // Initialize root payload in TypeResolver if not set
        if ($this->typeResolver->getRootPayload() === null) {
            $this->typeResolver->setRootPayload($data);
            $this->ownsRootPayload = true;
        }

        // Depth of this call once its payload is pushed (how many denormalize calls deep we are)
        $currentDepth = count($this->contextStack) + 1;

        // Detect circular references: if this class is already being processed at a SHALLOWER depth,
        // it's a cycle. If it's at the SAME depth, it's a sibling (e.g., array elements) which is OK.
        // Checked before any state is pushed, so the exception leaves this instance clean.
        if (isset($this->classDepthMap[$className]) && $this->classDepthMap[$className] < $currentDepth) {
            $this->releaseRootPayloadIfOwned();
            $stack = array_keys($this->classDepthMap);
            throw new \Pocta\DataMapper\Exceptions\CircularReferenceException(
                sprintf(
                    'Circular reference detected: attempting to denormalize "%s" which is already being processed in the call stack: %s',
                    $className,
                    implode(' -> ', array_merge($stack, [$className]))
                )
            );
        }

        $this->contextStack[] = $data;

        // Record the requested class at current depth (before discriminator resolution,
        // so the same key is removed in finally)
        $requestedClassName = $className;
        $this->classDepthMap[$requestedClassName] = $currentDepth;

        try {
            // Resolve the actual class to instantiate (handles discriminator mapping)
            $className = $this->resolveClassName($className, $data);

            $classInfo = $this->getClassInfo($className);
            /** @var ReflectionClass<T> $reflection */
            $reflection = $classInfo['reflection'];
            $constructor = $classInfo['constructor'];

            // Validate unknown keys in strict mode
            if ($this->strictMode) {
                $this->validateUnknownKeys($reflection, $constructor, $data);
            }

            if ($constructor !== null && $classInfo['parameters'] !== []) {
                $result = $this->createWithConstructor($reflection, $constructor, $data);
            } else {
                $result = $this->createWithoutConstructor($reflection, $data);
            }

            // Only throw for errors produced inside this invocation (including
            // its descendants). Errors present in $errorsBeforeCall belong to a
            // parent invocation on the same shared denormalizer and must not be
            // re-raised here — the parent will throw them itself.
            $newErrors = array_diff_key($this->errors, $errorsBeforeCall);
            if (!empty($newErrors)) {
                throw new ValidationException($newErrors);
            }

            return $result;
        } finally {
            // Always clean up stack and depth map
            array_pop($this->contextStack);
            unset($this->classDepthMap[$requestedClassName]);
            $this->releaseRootPayloadIfOwned();
        }
    }

    /**
     * Clears the root payload set by the outermost call of this instance, so the next
     * independent call does not see a stale payload (HydrationMode::FULL).
     */
    private function releaseRootPayloadIfOwned(): void
    {
        if ($this->contextStack === [] && $this->ownsRootPayload) {
            $this->typeResolver->setRootPayload(null);
            $this->ownsRootPayload = false;
        }
    }

    /**
     * Creates an object using constructor.
     *
     * @template T of object
     *
     * @param ReflectionClass<T> $reflection
     * @param \ReflectionMethod $constructor
     * @param array<string, mixed> $data
     *
     * @return T
     *
     * @throws ValidationException
     */
    private function createWithConstructor(
        ReflectionClass $reflection,
        \ReflectionMethod $constructor,
        array $data
    ): object {
        // Snapshot errors for the same reason as in denormalize(): on a shared
        // denormalizer instance, $this->errors may already contain keys from a
        // parent invocation — we must only react to NEW errors produced here.
        $errorsBeforeCall = $this->errors;
        $constructorArgs = [];

        foreach ($this->getClassInfo($reflection->getName())['parameters'] as $parameter) {
            // A variadic excluded from input receives no arguments (null would hit its type)
            if ($parameter->isVariadic() && !$this->isParameterDenormalizable($parameter)) {
                break;
            }
            $value = $this->getParameterValue($parameter, $data);
            $constructorArgs[] = $value;
        }

        // If there were NEW errors produced while resolving constructor arguments,
        // throw immediately because we cannot create the instance.
        $newErrors = array_diff_key($this->errors, $errorsBeforeCall);
        if (!empty($newErrors)) {
            throw new ValidationException($newErrors);
        }

        $instance = $reflection->newInstanceArgs($constructorArgs);

        // Set remaining properties that are not in constructor
        $this->setNonConstructorProperties($reflection, $instance, $data);

        return $instance;
    }

    /**
     * Creates an object without using constructor.
     *
     * @template T of object
     *
     * @param ReflectionClass<T> $reflection
     * @param array<string, mixed> $data
     *
     * @return T
     */
    private function createWithoutConstructor(ReflectionClass $reflection, array $data): object
    {
        $instance = $reflection->newInstanceWithoutConstructor();

        foreach ($this->getMappableProperties($reflection) as $property) {
            $this->setPropertyValue($property, $instance, $data);
        }

        return $instance;
    }

    /**
     * Gets value for a constructor parameter.
     *
     * @param ReflectionParameter $parameter
     * @param array<string, mixed> $data
     *
     * @return mixed
     */
    private function getParameterValue(ReflectionParameter $parameter, array $data): mixed
    {
        if (!$this->isParameterDenormalizable($parameter)) {
            return $this->getUnmappableParameterValue($parameter);
        }

        $field = $this->getFieldInfo($parameter);
        $jsonKey = $field['jsonKey'];
        $path = $field['path'];

        // Use PropertyPathResolver if path is specified
        if ($path !== null) {
            try {
                $hasKey = PropertyPathResolver::exists($data, $path);
                $value = $hasKey ? PropertyPathResolver::resolve($data, $path) : null;
            } catch (InvalidArgumentException $e) {
                // Invalid path syntax
                $fullPath = $this->buildFullFieldPath($path);
                $this->errors[$fullPath] = "Invalid property path syntax for parameter '{$parameter->getName()}': {$e->getMessage()}";
                return null;
            }
        } else {
            $hasKey = array_key_exists($jsonKey, $data);
            $value = $hasKey ? $data[$jsonKey] : null;
        }

        if (!$hasKey) {
            // If MapPropertyWithFunction present, compute value even when key is missing
            if (!$field['hasHydrator']) {
                if ($parameter->isDefaultValueAvailable()) {
                    return $parameter->getDefaultValue();
                }
                if ($parameter->allowsNull()) {
                    return null;
                }
                // Only add error if throwOnMissingData is enabled
                if ($this->throwOnMissingData) {
                    $fullPath = $this->buildFullFieldPath($path ?? $jsonKey);
                    if ($path !== null) {
                        // Provide more context for path resolution failures
                        $this->errors[$fullPath] = $this->buildPathResolutionError($parameter->getName(), $path, $data, $fullPath);
                    } else {
                        $this->errors[$fullPath] = "Missing required parameter '{$parameter->getName()}' at path '{$fullPath}'";
                    }
                }
                return null;
            }
        }

        // Apply hydrator and filters BEFORE type denormalization
        if ($field['hasHydrator']) {
            $value = $this->invokeHydrator($this->getHydrator($parameter), $value, $path ?? $jsonKey);
        }
        $value = $this->applyFilters($parameter, $value);

        $fullPath = $this->buildFullFieldPath($path ?? $jsonKey);
        $typed = $this->denormalizeValue(
            $value,
            $field['typeName'],
            $fullPath,
            $parameter->allowsNull(),
            $field['format'],
            $field['timezone'],
            $field['arrayOf'],
            $field['classType']
        );
        // Apply filters AFTER type denormalization
        $typed = $this->applyFilters($parameter, $typed);
        return $typed;
    }

    /**
     * Resolves a constructor argument for a parameter that must not be hydrated from input.
     *
     * @throws \LogicException When the parameter is required - the class cannot be built without input for it.
     */
    private function getUnmappableParameterValue(ReflectionParameter $parameter): mixed
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        if ($parameter->allowsNull()) {
            return null;
        }

        throw new \LogicException(sprintf(
            'Cannot instantiate "%s": constructor parameter $%s is required but excluded from input mapping '
            . '(#[Ignore], #[OutputOnly], or non-public in public-only mode). Add a default value or make it nullable.',
            $parameter->getDeclaringClass()?->getName() ?? 'unknown',
            $parameter->getName()
        ));
    }

    /**
     * Sets value for a property.
     *
     * @param ReflectionProperty $property
     * @param object $instance
     * @param array<string, mixed> $data
     */
    private function setPropertyValue(ReflectionProperty $property, object $instance, array $data): void
    {
        $field = $this->getFieldInfo($property);
        $jsonKey = $field['jsonKey'];
        $path = $field['path'];

        // Use PropertyPathResolver if path is specified
        if ($path !== null) {
            try {
                $hasKey = PropertyPathResolver::exists($data, $path);
                $value = $hasKey ? PropertyPathResolver::resolve($data, $path) : null;
            } catch (InvalidArgumentException $e) {
                // Invalid path syntax
                $fullPath = $this->buildFullFieldPath($path);
                $this->errors[$fullPath] = "Invalid property path syntax for property '{$property->getName()}': {$e->getMessage()}";
                return;
            }
        } else {
            $hasKey = array_key_exists($jsonKey, $data);
            $value = $hasKey ? $data[$jsonKey] : null;
        }

        if (!$hasKey) {
            // If MapPropertyWithFunction present, compute value even when key is missing
            if (!$field['hasHydrator']) {
                // For properties (not in constructor), missing data is not an error unless required
                // Check if property is required (not nullable and no default value)
                $isNullable = $this->isPropertyNullable($property);
                $hasDefault = $property->hasDefaultValue();
                if (!$isNullable && !$hasDefault && $this->throwOnMissingData) {
                    $fullPath = $this->buildFullFieldPath($path ?? $jsonKey);
                    $this->errors[$fullPath] = "Missing required property '{$property->getName()}' at path '{$fullPath}'";
                }
                return;
            }
        }

        // Apply hydrator and filters BEFORE type denormalization
        if ($field['hasHydrator']) {
            $value = $this->invokeHydrator($this->getHydrator($property), $value, $path ?? $jsonKey);
        }
        $value = $this->applyFilters($property, $value);
        $isNullable = $this->isPropertyNullable($property);

        $fullPath = $this->buildFullFieldPath($path ?? $jsonKey);
        $errorsBefore = $this->errors;
        $typedValue = $this->denormalizeValue(
            $value,
            $field['typeName'],
            $fullPath,
            $isNullable,
            $field['format'],
            $field['timezone'],
            $field['arrayOf'],
            $field['classType']
        );
        // Apply filters AFTER type denormalization
        $typedValue = $this->applyFilters($property, $typedValue);

        // Don't set the value if any error was added or changed during denormalization.
        // Value comparison (not just count) is required because the nested denormalize/
        // denormalizeValue catch blocks can overwrite an existing key with the same key
        // but a different message, which would leave count unchanged and then fall
        // through to setValue(null) on a non-nullable property (PHP TypeError).
        if ($this->errors !== $errorsBefore) {
            return;
        }

        // Defence in depth: never attempt to assign null to a non-nullable property.
        // If we reach this point with $typedValue === null and the property does not
        // allow null, an upstream path failed to record an error — skip assignment
        // instead of triggering a TypeError. The parent denormalize() will still
        // surface any new errors gathered so far.
        if ($typedValue === null && !$isNullable) {
            return;
        }

        $property->setValue($instance, $typedValue);
    }

    /**
     * Returns properties that take part in mapping (see PropertyVisibilityResolver).
     *
     * @param ReflectionClass<object> $reflection
     *
     * @return array<int, ReflectionProperty>
     */
    private function getMappableProperties(ReflectionClass $reflection): array
    {
        return $this->getClassInfo($reflection->getName())['denormalizable'][(int) $this->publicPropertiesOnly];
    }

    /**
     * Sets properties that are not in constructor.
     *
     * @param ReflectionClass<object> $reflection
     * @param object $instance
     * @param array<string, mixed> $data
     */
    private function setNonConstructorProperties(ReflectionClass $reflection, object $instance, array $data): void
    {
        $constructorParamNames = $this->getClassInfo($reflection->getName())['parameterNames'];

        foreach ($this->getMappableProperties($reflection) as $property) {
            // Skip if property is promoted (already set via constructor)
            if (in_array($property->getName(), $constructorParamNames, true)) {
                continue;
            }

            $this->setPropertyValue($property, $instance, $data);
        }
    }

    /**
     * Gets JSON key from parameter attributes.
     *
     * @param ReflectionParameter $parameter
     * @param array<\ReflectionAttribute<MapDateTimeProperty>> $dateTimeAttributes
     * @param array<\ReflectionAttribute<MapProperty>> $propertyAttributes
     * @return string
     */
    private function getJsonKeyFromParameter(
        ReflectionParameter $parameter,
        array $dateTimeAttributes,
        array $propertyAttributes
    ): string {
        // MapDateTimeProperty has priority
        if (!empty($dateTimeAttributes)) {
            $attr = $dateTimeAttributes[0]->newInstance();
            return $attr->name ?? $parameter->getName();
        }

        if (!empty($propertyAttributes)) {
            $attr = $propertyAttributes[0]->newInstance();
            return $attr->name ?? $parameter->getName();
        }

        return $parameter->getName();
    }

    /**
     * Gets JSON key from property attributes.
     *
     * @param ReflectionProperty $property
     * @param array<\ReflectionAttribute<MapDateTimeProperty>> $dateTimeAttributes
     * @param array<\ReflectionAttribute<MapProperty>> $propertyAttributes
     * @return string
     */
    private function getJsonKeyFromProperty(
        ReflectionProperty $property,
        array $dateTimeAttributes,
        array $propertyAttributes
    ): string {
        // MapDateTimeProperty has priority
        if (!empty($dateTimeAttributes)) {
            $attr = $dateTimeAttributes[0]->newInstance();
            return $attr->name ?? $property->getName();
        }

        if (!empty($propertyAttributes)) {
            $attr = $propertyAttributes[0]->newInstance();
            return $attr->name ?? $property->getName();
        }

        return $property->getName();
    }

    /**
     * Gets type from parameter attributes.
     *
     * @param ReflectionParameter $parameter
     * @param array<\ReflectionAttribute<MapDateTimeProperty>> $dateTimeAttributes
     * @param array<\ReflectionAttribute<MapProperty>> $propertyAttributes
     * @return string
     */
    private function getParameterTypeFromAttributes(
        ReflectionParameter $parameter,
        array $dateTimeAttributes,
        array $propertyAttributes
    ): string {
        // MapDateTimeProperty has priority
        if (!empty($dateTimeAttributes)) {
            $attr = $dateTimeAttributes[0]->newInstance();
            if ($attr->type !== null) {
                return $attr->type->value;
            }
        }

        if (!empty($propertyAttributes)) {
            $attr = $propertyAttributes[0]->newInstance();
            if ($attr->type !== null) {
                return $attr->type->value;
            }
        }

        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        return 'string';
    }

    /**
     * Gets type from property attributes.
     *
     * @param ReflectionProperty $property
     * @param array<\ReflectionAttribute<MapDateTimeProperty>> $dateTimeAttributes
     * @param array<\ReflectionAttribute<MapProperty>> $propertyAttributes
     * @return string
     */
    private function getPropertyTypeFromAttributes(
        ReflectionProperty $property,
        array $dateTimeAttributes,
        array $propertyAttributes
    ): string {
        // MapDateTimeProperty has priority
        if (!empty($dateTimeAttributes)) {
            $attr = $dateTimeAttributes[0]->newInstance();
            if ($attr->type !== null) {
                return $attr->type->value;
            }
        }

        if (!empty($propertyAttributes)) {
            $attr = $propertyAttributes[0]->newInstance();
            if ($attr->type !== null) {
                return $attr->type->value;
            }
        }

        $type = $property->getType();
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        return 'string';
    }

    /**
     * Checks if property is nullable.
     *
     * @param ReflectionProperty $property
     *
     * @return bool
     */
    private function isPropertyNullable(ReflectionProperty $property): bool
    {
        $type = $property->getType();
        return $type === null || $type->allowsNull();
    }

    /**
     * Denormalizes a value using the appropriate type handler.
     *
     * @param mixed $value
     * @param string $typeName
     * @param string $fieldName
     * @param bool $isNullable
     * @param string|null $format
     * @param string|null $timezone
     * @param string|null $arrayOf Class name or scalar type name
     * @param class-string|null $classType
     *
     * @return mixed
     */
    private function denormalizeValue(
        mixed $value,
        string $typeName,
        string $fieldName,
        bool $isNullable,
        ?string $format = null,
        ?string $timezone = null,
        ?string $arrayOf = null,
        ?string $classType = null
    ): mixed {
        try {
            $type = $this->typeResolver->getType($typeName, $format, $timezone, $arrayOf, $classType);
            return $type->denormalize($value, $fieldName, $isNullable);
        } catch (ValidationException $e) {
            // Merge errors from nested validation (e.g., arrayOf validation)
            foreach ($e->getErrors() as $errorKey => $errorMessage) {
                $this->errors[$errorKey] = $errorMessage;
            }
            // Return null or default value based on nullability
            return null;
        } catch (InvalidArgumentException $e) {
            // Collect error instead of throwing immediately
            $this->errors[$fieldName] = $e->getMessage();
            // Return null or default value based on nullability
            return null;
        }
    }

    /**
     * Gets format from parameter attributes.
     *
     * @param array<\ReflectionAttribute<MapDateTimeProperty>> $dateTimeAttributes
     * @return string|null
     */
    private function getFormatFromParameter(array $dateTimeAttributes): ?string
    {
        if (empty($dateTimeAttributes)) {
            return null;
        }

        $attr = $dateTimeAttributes[0]->newInstance();
        return $attr->format;
    }

    /**
     * Gets timezone from parameter attributes.
     *
     * @param array<\ReflectionAttribute<MapDateTimeProperty>> $dateTimeAttributes
     * @return string|null
     */
    private function getTimezoneFromParameter(array $dateTimeAttributes): ?string
    {
        if (empty($dateTimeAttributes)) {
            return null;
        }

        $attr = $dateTimeAttributes[0]->newInstance();
        return $attr->timezone;
    }

    /**
     * Gets format from property attributes.
     *
     * @param array<\ReflectionAttribute<MapDateTimeProperty>> $dateTimeAttributes
     * @return string|null
     */
    private function getFormatFromProperty(array $dateTimeAttributes): ?string
    {
        if (empty($dateTimeAttributes)) {
            return null;
        }

        $attr = $dateTimeAttributes[0]->newInstance();
        return $attr->format;
    }

    /**
     * Gets timezone from property attributes.
     *
     * @param array<\ReflectionAttribute<MapDateTimeProperty>> $dateTimeAttributes
     * @return string|null
     */
    private function getTimezoneFromProperty(array $dateTimeAttributes): ?string
    {
        if (empty($dateTimeAttributes)) {
            return null;
        }

        $attr = $dateTimeAttributes[0]->newInstance();
        return $attr->timezone;
    }

    /**
     * Gets arrayOf from parameter attributes.
     *
     * @param array<\ReflectionAttribute<MapDateTimeProperty>> $dateTimeAttributes
     * @param array<\ReflectionAttribute<MapProperty>> $propertyAttributes
     * @return class-string|string|null
     */
    private function getArrayOfFromParameter(array $dateTimeAttributes, array $propertyAttributes): ?string
    {
        if (!empty($dateTimeAttributes)) {
            $attr = $dateTimeAttributes[0]->newInstance();
            // Convert PropertyType or ArrayElementType enum to string value if needed
            if ($attr->arrayOf instanceof \Pocta\DataMapper\Attributes\PropertyType) {
                return $attr->arrayOf->value;
            }
            if ($attr->arrayOf instanceof \Pocta\DataMapper\Attributes\ArrayElementType) {
                return $attr->arrayOf->value;
            }
            return $attr->arrayOf;
        }

        if (!empty($propertyAttributes)) {
            $attr = $propertyAttributes[0]->newInstance();
            // Convert PropertyType or ArrayElementType enum to string value if needed
            if ($attr->arrayOf instanceof \Pocta\DataMapper\Attributes\PropertyType) {
                return $attr->arrayOf->value;
            }
            if ($attr->arrayOf instanceof \Pocta\DataMapper\Attributes\ArrayElementType) {
                return $attr->arrayOf->value;
            }
            return $attr->arrayOf;
        }

        return null;
    }

    /**
     * Gets arrayOf from property attributes.
     *
     * @param array<\ReflectionAttribute<MapDateTimeProperty>> $dateTimeAttributes
     * @param array<\ReflectionAttribute<MapProperty>> $propertyAttributes
     * @return class-string|string|null
     */
    private function getArrayOfFromProperty(array $dateTimeAttributes, array $propertyAttributes): ?string
    {
        if (!empty($dateTimeAttributes)) {
            $attr = $dateTimeAttributes[0]->newInstance();
            // Convert PropertyType or ArrayElementType enum to string value if needed
            if ($attr->arrayOf instanceof \Pocta\DataMapper\Attributes\PropertyType) {
                return $attr->arrayOf->value;
            }
            if ($attr->arrayOf instanceof \Pocta\DataMapper\Attributes\ArrayElementType) {
                return $attr->arrayOf->value;
            }
            return $attr->arrayOf;
        }

        if (!empty($propertyAttributes)) {
            $attr = $propertyAttributes[0]->newInstance();
            // Convert PropertyType or ArrayElementType enum to string value if needed
            if ($attr->arrayOf instanceof \Pocta\DataMapper\Attributes\PropertyType) {
                return $attr->arrayOf->value;
            }
            if ($attr->arrayOf instanceof \Pocta\DataMapper\Attributes\ArrayElementType) {
                return $attr->arrayOf->value;
            }
            return $attr->arrayOf;
        }

        return null;
    }

    /**
     * Gets classType from parameter attributes.
     *
     * @param array<\ReflectionAttribute<MapProperty>> $propertyAttributes
     * @return class-string|null
     */
    private function getClassTypeFromParameter(array $propertyAttributes): ?string
    {
        if (empty($propertyAttributes)) {
            return null;
        }

        $attr = $propertyAttributes[0]->newInstance();
        return $attr->classType;
    }

    /**
     * Gets classType from property attributes.
     *
     * @param array<\ReflectionAttribute<MapProperty>> $propertyAttributes
     * @return class-string|null
     */
    private function getClassTypeFromProperty(array $propertyAttributes): ?string
    {
        if (empty($propertyAttributes)) {
            return null;
        }

        $attr = $propertyAttributes[0]->newInstance();
        return $attr->classType;
    }

    /**
     * Applies filter attributes of the property/parameter, in declaration order.
     * The chain is built on first use (all attributes are instantiated, as before); built-in filter
     * instances are reused, user-defined filters are instantiated per apply (see FilterChain).
     */
    private function applyFilters(ReflectionProperty|ReflectionParameter $reflector, mixed $value): mixed
    {
        $id = spl_object_id($reflector);
        $this->filterCache[$id] ??= \Pocta\DataMapper\Attributes\Filters\FilterChain::fromAttributes($reflector->getAttributes());

        return $this->filterCache[$id]->apply($value);
    }

    private function getHydrator(ReflectionProperty|ReflectionParameter $reflector): \Pocta\DataMapper\Attributes\MapPropertyWithFunction
    {
        $id = spl_object_id($reflector);
        if (!isset($this->hydratorCache[$id])) {
            /** @var \Pocta\DataMapper\Attributes\MapPropertyWithFunction $hydrator */
            $hydrator = $reflector->getAttributes(\Pocta\DataMapper\Attributes\MapPropertyWithFunction::class)[0]->newInstance();
            $this->hydratorCache[$id] = $hydrator;
        }

        return $this->hydratorCache[$id];
    }

    /**
     * Mapping settings derived from #[MapDateTimeProperty] / #[MapProperty] (the former has priority).
     *
     * @return array{
     *     jsonKey: string,
     *     path: ?string,
     *     typeName: string,
     *     format: ?string,
     *     timezone: ?string,
     *     arrayOf: ?string,
     *     classType: ?class-string,
     *     hasHydrator: bool
     * }
     */
    private function getFieldInfo(ReflectionProperty|ReflectionParameter $reflector): array
    {
        $id = spl_object_id($reflector);
        if (isset($this->fieldCache[$id])) {
            return $this->fieldCache[$id];
        }

        $dateTimeAttributes = $reflector->getAttributes(MapDateTimeProperty::class);
        $propertyAttributes = $reflector->getAttributes(MapProperty::class);

        if ($reflector instanceof ReflectionParameter) {
            $info = [
                'jsonKey' => $this->getJsonKeyFromParameter($reflector, $dateTimeAttributes, $propertyAttributes),
                'path' => $this->getPathFromParameter($dateTimeAttributes, $propertyAttributes),
                'typeName' => $this->getParameterTypeFromAttributes($reflector, $dateTimeAttributes, $propertyAttributes),
                'format' => $this->getFormatFromParameter($dateTimeAttributes),
                'timezone' => $this->getTimezoneFromParameter($dateTimeAttributes),
                'arrayOf' => $this->getArrayOfFromParameter($dateTimeAttributes, $propertyAttributes),
                'classType' => $this->getClassTypeFromParameter($propertyAttributes),
            ];
        } else {
            $info = [
                'jsonKey' => $this->getJsonKeyFromProperty($reflector, $dateTimeAttributes, $propertyAttributes),
                'path' => $this->getPathFromProperty($dateTimeAttributes, $propertyAttributes),
                'typeName' => $this->getPropertyTypeFromAttributes($reflector, $dateTimeAttributes, $propertyAttributes),
                'format' => $this->getFormatFromProperty($dateTimeAttributes),
                'timezone' => $this->getTimezoneFromProperty($dateTimeAttributes),
                'arrayOf' => $this->getArrayOfFromProperty($dateTimeAttributes, $propertyAttributes),
                'classType' => $this->getClassTypeFromProperty($propertyAttributes),
            ];
        }
        $info['hasHydrator'] = $reflector->getAttributes(\Pocta\DataMapper\Attributes\MapPropertyWithFunction::class) !== [];

        return $this->fieldCache[$id] = $info;
    }

    private function isParameterDenormalizable(ReflectionParameter $parameter): bool
    {
        $id = spl_object_id($parameter);
        $this->parameterDenormalizableCache[$id] ??= [
            PropertyVisibilityResolver::isParameterDenormalizable($parameter, false),
            PropertyVisibilityResolver::isParameterDenormalizable($parameter, true),
        ];

        return $this->parameterDenormalizableCache[$id][(int) $this->publicPropertiesOnly];
    }

    /**
     * Reflection of a class, resolved once per Denormalizer instance.
     *
     * @return array{
     *     reflection: ReflectionClass<object>,
     *     constructor: ?\ReflectionMethod,
     *     parameters: list<ReflectionParameter>,
     *     parameterNames: list<string>,
     *     properties: list<ReflectionProperty>,
     *     denormalizable: array{0: list<ReflectionProperty>, 1: list<ReflectionProperty>},
     *     discriminator: ?DiscriminatorMap
     * }
     */
    private function getClassInfo(string $className): array
    {
        if (isset($this->classCache[$className])) {
            return $this->classCache[$className];
        }

        /** @var class-string $className */
        $reflection = new ReflectionClass($className);
        $constructor = $reflection->getConstructor();
        $parameters = $constructor !== null ? $constructor->getParameters() : [];
        $properties = $reflection->getProperties();

        $discriminatorAttributes = $reflection->getAttributes(DiscriminatorMap::class);

        return $this->classCache[$className] = [
            'reflection' => $reflection,
            'constructor' => $constructor,
            'parameters' => $parameters,
            'parameterNames' => array_map(fn(ReflectionParameter $p) => $p->getName(), $parameters),
            'properties' => $properties,
            'denormalizable' => [
                array_values(array_filter(
                    $properties,
                    fn(ReflectionProperty $p) => PropertyVisibilityResolver::isPropertyDenormalizable($p, false)
                )),
                array_values(array_filter(
                    $properties,
                    fn(ReflectionProperty $p) => PropertyVisibilityResolver::isPropertyDenormalizable($p, true)
                )),
            ],
            'discriminator' => $discriminatorAttributes !== [] ? $discriminatorAttributes[0]->newInstance() : null,
        ];
    }

    /** Invokes configured hydrator function with payload selected by mode. */
    private function invokeHydrator(\Pocta\DataMapper\Attributes\MapPropertyWithFunction $cfg, mixed $value, string $fieldName): mixed
    {
        $callable = $cfg->function;
        if (!is_callable($callable)) {
            // @phpstan-ignore-next-line function.alreadyNarrowedType
            $callableStr = is_array($callable) ? implode('::', $callable) : (string) $callable;
            $fullPath = $this->buildFullFieldPath($fieldName);
            $this->errors[$fullPath] = "Hydrator function '{$callableStr}' is not callable at path '{$fullPath}'";
            return $value;
        }

        $payload = match ($cfg->mode) {
            \Pocta\DataMapper\Attributes\HydrationMode::VALUE => $value,
            \Pocta\DataMapper\Attributes\HydrationMode::PARENT => $this->getCurrentPayload(),
            \Pocta\DataMapper\Attributes\HydrationMode::FULL => $this->getRootPayload(),
        };

        try {
            return \call_user_func($callable, $payload);
        } catch (\Throwable $e) {
            $fullPath = $this->buildFullFieldPath($fieldName);
            $this->errors[$fullPath] = "Hydrator function error at path '{$fullPath}': " . $e->getMessage();
            return $value;
        }
    }

    /**
     * Returns current (top) payload or empty array.
     * @return array<string, mixed>
     */
    private function getCurrentPayload(): array
    {
        return $this->contextStack[count($this->contextStack) - 1] ?? [];
    }

    /**
     * Returns root (bottom) payload or current if stack is empty.
     *
     * @return array<string, mixed>
     */
    private function getRootPayload(): array
    {
        // Try to get root payload from TypeResolver first (shared across instances)
        $rootPayload = $this->typeResolver->getRootPayload();
        if ($rootPayload !== null) {
            return $rootPayload;
        }
        // Fallback to context stack
        return $this->contextStack[0] ?? ($this->contextStack[count($this->contextStack) - 1] ?? []);
    }

    /**
     * Resolves the actual class name to instantiate based on discriminator mapping.
     * If no discriminator is present, returns the original class name.
     *
     * @template T of object
     * @param class-string<T> $className
     * @param array<string, mixed> $data
     * @return class-string<T>
     * @throws ValidationException
     */
    private function resolveClassName(string $className, array $data): string
    {
        $discriminator = $this->getClassInfo($className)['discriminator'];

        if ($discriminator === null) {
            return $className;
        }

        // Check if discriminator property exists in data
        if (!array_key_exists($discriminator->property, $data)) {
            if ($this->throwOnMissingData) {
                $fullPath = $this->buildFullFieldPath($discriminator->property);
                $this->errors[$fullPath] = "Missing discriminator property '{$discriminator->property}' at path '{$fullPath}'";
                throw new ValidationException($this->errors);
            }
            // If not throwing, return the base class name (fallback)
            return $className;
        }

        $discriminatorValue = $data[$discriminator->property];

        // Ensure discriminator value is a string (for array key access)
        if (!is_string($discriminatorValue) && !is_int($discriminatorValue)) {
            $fullPath = $this->buildFullFieldPath($discriminator->property);
            $type = get_debug_type($discriminatorValue);
            $this->errors[$fullPath] = "Discriminator value must be string or int, {$type} given at path '{$fullPath}'";
            throw new ValidationException($this->errors);
        }

        // Convert to string for consistent array key access
        $discriminatorKey = (string) $discriminatorValue;

        // Check if discriminator value is mapped to a class
        if (!isset($discriminator->mapping[$discriminatorKey])) {
            $fullPath = $this->buildFullFieldPath($discriminator->property);
            $availableValues = implode(', ', array_keys($discriminator->mapping));
            $this->errors[$fullPath] = "Unknown discriminator value '{$discriminatorKey}' at path '{$fullPath}'. Available values: {$availableValues}";
            throw new ValidationException($this->errors);
        }

        /** @var class-string<T> */
        return $discriminator->mapping[$discriminatorKey];
    }

    /**
     * Validates that no unknown keys are present in the input data.
     *
     * @param ReflectionClass<object> $reflection
     * @param \ReflectionMethod|null $constructor
     * @param array<string, mixed> $data
     */
    private function validateUnknownKeys(
        ReflectionClass $reflection,
        ?\ReflectionMethod $constructor,
        array $data
    ): void {
        $knownKeys = $this->knownKeysCache[$reflection->getName()][(int) $this->publicPropertiesOnly]
            ??= $this->collectKnownKeys($reflection, $constructor);

        // Check for unknown keys
        foreach (array_keys($data) as $inputKey) {
            if (!isset($knownKeys[(string) $inputKey])) {
                $fullPath = $this->buildFullFieldPath((string) $inputKey);
                $this->errors[$fullPath] = "Unknown key '{$inputKey}' at path '{$fullPath}' is not allowed in strict mode";
            }
        }
    }

    /**
     * Input keys accepted in strict mode for a class under the current public-only setting.
     *
     * @param ReflectionClass<object> $reflection
     * @return array<string, true>
     */
    private function collectKnownKeys(ReflectionClass $reflection, ?\ReflectionMethod $constructor): array
    {
        $knownKeys = [];

        // Collect keys from constructor parameters
        if ($constructor !== null) {
            foreach ($this->getClassInfo($reflection->getName())['parameters'] as $parameter) {
                if (!$this->isParameterDenormalizable($parameter)) {
                    continue;
                }
                $field = $this->getFieldInfo($parameter);
                $knownKeys[$field['path'] !== null ? $this->getPathRootKey($field['path']) : $field['jsonKey']] = true;
            }
        }

        // Collect keys from properties (including output-only ones - accepted and discarded)
        foreach ($this->getClassInfo($reflection->getName())['properties'] as $property) {
            if (!PropertyVisibilityResolver::isPropertyKnownForInput($property, $this->publicPropertiesOnly)) {
                continue;
            }
            $field = $this->getFieldInfo($property);
            $knownKeys[$field['path'] !== null ? $this->getPathRootKey($field['path']) : $field['jsonKey']] = true;
        }

        return $knownKeys;
    }

    /**
     * Returns the top-level input key a property path reads from (e.g. "address" for "address.city").
     * Only the root is checked in strict mode; keys nested under it are not validated.
     * Invalid path syntax yields the raw path - the error is reported when the value is resolved.
     */
    private function getPathRootKey(string $path): string
    {
        try {
            return (string) PropertyPathResolver::getRootKey($path);
        } catch (InvalidArgumentException) {
            return $path;
        }
    }

    /**
     * Gets path from parameter attributes.
     *
     * @param array<\ReflectionAttribute<MapDateTimeProperty>> $dateTimeAttributes
     * @param array<\ReflectionAttribute<MapProperty>> $propertyAttributes
     * @return string|null
     */
    private function getPathFromParameter(array $dateTimeAttributes, array $propertyAttributes): ?string
    {
        // MapDateTimeProperty has priority
        if (!empty($dateTimeAttributes)) {
            $attr = $dateTimeAttributes[0]->newInstance();
            return $attr->path;
        }

        if (!empty($propertyAttributes)) {
            $attr = $propertyAttributes[0]->newInstance();
            return $attr->path;
        }

        return null;
    }

    /**
     * Gets path from property attributes.
     *
     * @param array<\ReflectionAttribute<MapDateTimeProperty>> $dateTimeAttributes
     * @param array<\ReflectionAttribute<MapProperty>> $propertyAttributes
     * @return string|null
     */
    private function getPathFromProperty(array $dateTimeAttributes, array $propertyAttributes): ?string
    {
        // MapDateTimeProperty has priority
        if (!empty($dateTimeAttributes)) {
            $attr = $dateTimeAttributes[0]->newInstance();
            return $attr->path;
        }

        if (!empty($propertyAttributes)) {
            $attr = $propertyAttributes[0]->newInstance();
            return $attr->path;
        }

        return null;
    }

    /**
     * Builds a detailed error message for path resolution failures.
     *
     * @param string $propertyName Property or parameter name
     * @param string $path The property path that failed
     * @param array<string, mixed> $data The input data
     * @param string $fullPath Full path including any prefix
     * @return string Detailed error message with context
     */
    private function buildPathResolutionError(string $propertyName, string $path, array $data, string $fullPath): string
    {
        // Parse the path to find where it fails
        $segments = explode('.', str_replace(['[', ']'], ['.', ''], $path));
        $current = $data;
        $failedAt = null;
        $availableKeys = [];

        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }

            if (!is_array($current)) {
                $failedAt = implode('.', array_slice($segments, 0, $index));
                break;
            }

            // Check if it's a numeric index
            if (is_numeric($segment)) {
                $idx = (int) $segment;
                if (!isset($current[$idx])) {
                    $failedAt = implode('.', array_slice($segments, 0, $index + 1));
                    $availableKeys = array_keys($current);
                    break;
                }
                $current = $current[$idx];
            } else {
                if (!array_key_exists($segment, $current)) {
                    $failedAt = implode('.', array_slice($segments, 0, $index + 1));
                    $availableKeys = array_keys($current);
                    break;
                }
                $current = $current[$segment];
            }
        }

        // Build error message with context
        $message = "Missing required property '{$propertyName}' at path '{$fullPath}'";

        if ($failedAt !== null) {
            $message .= " (path resolution failed at '{$failedAt}'";

            if (!empty($availableKeys)) {
                // Filter out numeric keys for cleaner output
                $namedKeys = array_filter($availableKeys, fn($k) => !is_int($k));
                if (!empty($namedKeys)) {
                    $keysList = implode(', ', array_slice($namedKeys, 0, 5));
                    if (count($namedKeys) > 5) {
                        $keysList .= ', ...';
                    }
                    $message .= ", available keys: [{$keysList}]";
                } else {
                    // All keys are numeric - it's an array
                    $message .= ", array has " . count($availableKeys) . " elements";
                }
            }

            $message .= ')';
        }

        return $message;
    }
}
