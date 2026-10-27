<?php

declare(strict_types=1);

namespace Pocta\DataMapper;

use Pocta\DataMapper\Cache\ArrayCache;
use Pocta\DataMapper\Cache\CacheInterface;
use Pocta\DataMapper\Cache\ClassMetadataFactory;
use Pocta\DataMapper\Denormalizer\Denormalizer;
use Pocta\DataMapper\Normalizer\Normalizer;
use Pocta\DataMapper\Types\TypeResolver;
use Pocta\DataMapper\PropertyVisibilityResolver;
use Pocta\DataMapper\Events\EventDispatcher;
use Pocta\DataMapper\Events\PreDenormalizeEvent;
use Pocta\DataMapper\Events\PostDenormalizeEvent;
use Pocta\DataMapper\Events\PreNormalizeEvent;
use Pocta\DataMapper\Events\PostNormalizeEvent;
use Pocta\DataMapper\Events\DenormalizationErrorEvent;
use Pocta\DataMapper\Events\ValidationEvent;
use Pocta\DataMapper\Validation\Validator;
use Pocta\DataMapper\Validation\ValidatorResolverInterface;
use Pocta\DataMapper\Debug\Debugger;
use Pocta\DataMapper\Debug\Profiler;
use InvalidArgumentException;
use Pocta\DataMapper\Exceptions\InvalidInputException;
use JsonException;

class Mapper
{
    private Denormalizer $denormalizer;
    private Normalizer $normalizer;
    private ClassMetadataFactory $metadataFactory;
    private EventDispatcher $eventDispatcher;
    private Validator $validator;
    private TypeResolver $typeResolver;
    private bool $autoValidate;
    private bool $strictMode;
    private bool $throwOnMissingData;
    private bool $publicPropertiesOnly;
    /** @var int<1, max> */
    private int $jsonDepth;
    private ?Debugger $debugger;
    private ?Profiler $profiler;

    /**
     * @param MapperOptions|null $options Mapper configuration options.
     * @param Denormalizer|null $denormalizer Custom denormalizer instance.
     * @param Normalizer|null $normalizer Custom normalizer instance.
     * @param CacheInterface|null $cache Cache implementation (null = default ArrayCache, use NullCache to disable).
     * @param TypeResolver|null $typeResolver Custom type resolver instance.
     * @param EventDispatcher|null $eventDispatcher Event dispatcher for hooks (null = creates new one).
     * @param Validator|null $validator Custom validator instance.
     * @param ValidatorResolverInterface|null $validatorResolver Resolver for constraint validators (e.g. from DI container). Ignored when custom $validator is provided.
     * @param Debugger|null $debugger Debugger for logging operations (null = disabled).
     * @param Profiler|null $profiler Profiler for measuring performance (null = disabled).
     */
    public function __construct(
        ?MapperOptions $options = null,
        ?Denormalizer $denormalizer = null,
        ?Normalizer $normalizer = null,
        ?CacheInterface $cache = null,
        ?TypeResolver $typeResolver = null,
        ?EventDispatcher $eventDispatcher = null,
        ?Validator $validator = null,
        ?ValidatorResolverInterface $validatorResolver = null,
        ?Debugger $debugger = null,
        ?Profiler $profiler = null
    ) {
        $options = $options ?? new MapperOptions();

        $cache = $cache ?? new ArrayCache();
        $this->typeResolver = $typeResolver ?? new TypeResolver();
        $this->eventDispatcher = $eventDispatcher ?? new EventDispatcher();
        $this->metadataFactory = new ClassMetadataFactory($cache);
        $this->validator = $validator ?? new Validator($validatorResolver);
        $this->autoValidate = $options->autoValidate;
        $this->strictMode = $options->strictMode;
        $this->throwOnMissingData = $options->throwOnMissingData;
        $this->publicPropertiesOnly = $options->publicPropertiesOnly;
        $this->jsonDepth = max(1, $options->jsonDepth);
        $this->debugger = $debugger;
        $this->profiler = $profiler;

        $this->denormalizer = $denormalizer ?? new Denormalizer($this->typeResolver, $this->metadataFactory);
        $this->denormalizer->setStrictMode($this->strictMode);
        $this->denormalizer->setThrowOnMissingData($this->throwOnMissingData);
        $this->denormalizer->setPublicPropertiesOnly($this->publicPropertiesOnly);
        $this->typeResolver->setStrictMode($this->strictMode);
        $this->typeResolver->setSkipNullValues($options->skipNullValues);
        $this->typeResolver->setPublicPropertiesOnly($this->publicPropertiesOnly);
        $this->typeResolver->setSymmetricPropertyPaths($options->symmetricPropertyPaths);
        $this->normalizer = $normalizer ?? new Normalizer($this->typeResolver, $this->metadataFactory, $options->skipNullValues);
        $this->normalizer->setPublicPropertiesOnly($this->publicPropertiesOnly);
        $this->normalizer->setSymmetricPropertyPaths($options->symmetricPropertyPaths);

        // Setup event listener for debugging events
        if ($this->debugger?->isEnabled()) {
            $this->setupDebugEventListeners();
        }
    }

    /**
     * Get the metadata factory (for cache management).
     */
    public function getMetadataFactory(): ClassMetadataFactory
    {
        return $this->metadataFactory;
    }

    /**
     * Get the event dispatcher.
     */
    public function getEventDispatcher(): EventDispatcher
    {
        return $this->eventDispatcher;
    }

    /**
     * Clear metadata cache for specific class or all classes.
     *
     * @param class-string|null $className
     */
    public function clearCache(?string $className = null): void
    {
        $this->metadataFactory->clearCache($className);
    }

    /**
     * Add event listener.
     *
     * @param string $eventName Event class name.
     * @param callable $listener Callable accepting EventInterface.
     * @param int $priority Higher priority = called first.
     */
    public function addEventListener(string $eventName, callable $listener, int $priority = 0): void
    {
        $this->eventDispatcher->addEventListener($eventName, $listener, $priority);
    }

    /**
     * Remove event listener.
     */
    public function removeEventListener(string $eventName, callable $listener): void
    {
        $this->eventDispatcher->removeEventListener($eventName, $listener);
    }

    /**
     * Get the validator.
     */
    public function getValidator(): Validator
    {
        return $this->validator;
    }

    /**
     * Get the debugger.
     */
    public function getDebugger(): ?Debugger
    {
        return $this->debugger;
    }

    /**
     * Get the profiler.
     */
    public function getProfiler(): ?Profiler
    {
        return $this->profiler;
    }

    /**
     * Enable or disable auto-validation.
     */
    public function setAutoValidate(bool $autoValidate): void
    {
        $this->autoValidate = $autoValidate;
    }

    /**
     * Check if auto-validation is enabled.
     */
    public function isAutoValidate(): bool
    {
        return $this->autoValidate;
    }

    /**
     * Enable or disable strict mode.
     */
    public function setStrictMode(bool $strictMode): void
    {
        $this->strictMode = $strictMode;
        $this->denormalizer->setStrictMode($strictMode);
        $this->typeResolver->setStrictMode($strictMode);
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
        $this->denormalizer->setThrowOnMissingData($throwOnMissingData);
    }

    /**
     * Check if throwing on missing data is enabled.
     */
    public function isThrowOnMissingData(): bool
    {
        return $this->throwOnMissingData;
    }

    /**
     * Manually validate an object.
     *
     * @param object $object
     * @param bool $throw Whether to throw exception on validation failure.
     *
     * @return array<string, string> Array of errors (empty if valid).
     *
     * @throws \Pocta\DataMapper\Exceptions\ValidationException
     */
    public function validate(object $object, bool $throw = true): array
    {
        return $this->validator->validate($object, $throw);
    }

    /**
     * Maps JSON string to an object of the specified class.
     *
     * @template T of object
     *
     * @param string $json
     * @param class-string<T> $className
     *
     * @return T
     *
     * @throws JsonException When JSON is malformed or exceeds the configured depth.
     * @throws InvalidInputException When JSON is not an object.
     * @throws \Pocta\DataMapper\Exceptions\ValidationException
     */
    public function fromJson(string $json, string $className): object
    {
        $this->profiler?->start('fromJson');
        $this->debugger?->logOperation('fromJson', $json, $className);

        try {
            $data = json_decode($json, true, $this->jsonDepth, JSON_THROW_ON_ERROR);

            // Decoding to arrays loses the object/list distinction ("[]" vs "{}"),
            // so check the top-level JSON token itself.
            if (!is_array($data) || !$this->jsonStartsWith($json, '{')) {
                throw new InvalidInputException('JSON must decode to an associative array (object)');
            }

            /** @var array<string, mixed> $data */
            return $this->fromArray($data, $className);
        } finally {
            $this->profiler?->stop('fromJson');
        }
    }

    /**
     * Maps an associative array to an object of the specified class.
     *
     * @template T of object
     *
     * @param array<mixed> $data
     * @param class-string<T> $className
     *
     * @return T
     *
     * @throws InvalidInputException When data is a non-empty list instead of an associative array.
     * @throws \Pocta\DataMapper\Exceptions\ValidationException
     */
    public function fromArray(array $data, string $className): object
    {
        $this->profiler?->start('fromArray');
        $this->debugger?->logOperation('fromArray', $data, $className);

        // Dispatch PreDenormalize event (listeners may reshape the input, e.g. wrap a list)
        $preEvent = new PreDenormalizeEvent($data, $className);
        $this->eventDispatcher->dispatch($preEvent);
        $data = $preEvent->data;

        try {
            if ($data !== [] && array_is_list($data)) {
                throw new InvalidInputException('Data must be an associative array (object), list given');
            }
            /** @var array<string, mixed> $data */

            // Set root payload in TypeResolver for nested denormalizations
            $this->typeResolver->setRootPayload($data);

            $this->profiler?->start('denormalize');

            /** @var T $object */
            $object = $this->denormalizer->denormalize($data, $className);

            $this->profiler?->stop('denormalize');

            // Dispatch PostDenormalize event
            $postEvent = new PostDenormalizeEvent($object, $data, $className);
            $this->eventDispatcher->dispatch($postEvent);
            /** @var T $object */
            $object = $postEvent->object;

            // Auto-validation if enabled
            if ($this->autoValidate) {
                $this->profiler?->start('validation');

                $errors = $this->validator->validate($object, throw: false);

                // Dispatch validation event
                $validationEvent = new ValidationEvent($object, $errors);
                $this->eventDispatcher->dispatch($validationEvent);

                $this->profiler?->stop('validation');

                // Throw if there are errors
                if ($validationEvent->hasErrors()) {
                    throw new \Pocta\DataMapper\Exceptions\ValidationException($validationEvent->errors);
                }
            }

            return $object;
        } catch (\Throwable $e) {
            // Dispatch error event
            $errorEvent = new DenormalizationErrorEvent($e, $data, $className);
            $this->eventDispatcher->dispatch($errorEvent);

            // Re-throw if not suppressed
            if (!$errorEvent->isExceptionSuppressed()) {
                throw $e;
            }

            // Return null if suppressed (would need nullable return type in real impl)
            throw $e; // For now still throw
        } finally {
            // Clear root payload after denormalization
            $this->typeResolver->setRootPayload(null);
            $this->profiler?->stop('fromArray');
        }
    }

    /**
     * Maps a source object (Entity, DTO, etc.) to a target DTO class.
     * Supports property paths, getters, and nested object navigation.
     *
     * Example:
     * ```php
     * // Doctrine Entity
     * $user = $entityManager->find(User::class, 1);
     *
     * // Map to DTO
     * $userDto = $mapper->fromObject($user, UserDTO::class);
     * ```
     *
     * @template T of object
     *
     * @param object $source Source object (Doctrine Entity, DTO, etc.).
     * @param class-string<T> $className Target DTO class name.
     *
     * @return T Mapped target object.
     */
    public function fromObject(object $source, string $className): object
    {
        $this->profiler?->start('fromObject');
        $this->debugger?->logOperation('fromObject', get_class($source), $className);

        try {
            // Convert object to array using ObjectPathResolver for properties with MapFrom attribute
            $data = $this->objectToArray($source, $className);

            // Use regular fromArray flow
            return $this->fromArray($data, $className);
        } finally {
            $this->profiler?->stop('fromObject');
        }
    }

    /**
     * Convert source object to array based on target class MapFrom attributes.
     *
     * @param object $source
     * @param class-string $className
     *
     * @return array<string, mixed>
     */
    private function objectToArray(object $source, string $className): array
    {
        $metadata = $this->metadataFactory->getMetadata($className);
        $data = [];

        // Process constructor parameters
        if ($metadata->constructor !== null) {
            foreach ($metadata->constructor->parameters as $param) {
            $mapFrom = $param->mapFrom;

            if ($mapFrom !== null) {
                // Use MapFrom attribute path
                $value = \Pocta\DataMapper\ObjectPathResolver::resolve($source, $mapFrom->path);
                $data[$param->name] = $value;
            } else {
                // Try automatic mapping: property name -> getter
                $value = \Pocta\DataMapper\ObjectPathResolver::resolve($source, $param->name);
                if ($value !== null) {
                    $data[$param->name] = $value;
                }
            }
            }
        }

        // Process regular properties
        foreach ($metadata->properties as $property) {
            $mapFrom = $property->mapFrom;

            if ($mapFrom !== null) {
                // Use MapFrom attribute path
                $value = \Pocta\DataMapper\ObjectPathResolver::resolve($source, $mapFrom->path);
                $data[$property->name] = $value;
            } else {
                // Try automatic mapping: property name -> getter
                $value = \Pocta\DataMapper\ObjectPathResolver::resolve($source, $property->name);
                if ($value !== null) {
                    $data[$property->name] = $value;
                }
            }
        }

        return $data;
    }

    /**
     * Merges partial data from array into an existing object.
     * Only updates properties that are present in the input data.
     *
     * @template T of object
     *
     * @param array<string, mixed> $data Partial data to merge.
     * @param T $target Existing object to update.
     * @param bool $skipNull Skip null values in input data (don't overwrite with null).
     *
     * @return T Updated object (same instance).
     *
     * @throws \Pocta\DataMapper\Exceptions\ValidationException
     */
    public function merge(array $data, object $target, bool $skipNull = false): object
    {
        $this->profiler?->start('merge');
        $this->debugger?->logOperation('merge', $data, get_class($target));

        try {
            $reflection = new \ReflectionClass($target);

            foreach ($data as $key => $value) {
                // Skip null values if requested
                if ($skipNull && $value === null) {
                    continue;
                }

                // Try to find property by checking both direct name and MapProperty attribute
                $property = $this->findPropertyByJsonKey($reflection, $key);

                if ($property === null) {
                    // Property not found
                    if ($this->strictMode) {
                        throw new \Pocta\DataMapper\Exceptions\ValidationException([
                            $key => "Unknown key '{$key}' is not allowed in strict mode"
                        ]);
                    }
                    continue; // Skip unknown properties in non-strict mode
                }

                // Known but output-only key - accepted and discarded
                if (!PropertyVisibilityResolver::isPropertyDenormalizable($property, $this->publicPropertiesOnly)) {
                    continue;
                }

                // Get property type information
                $dateTimeAttributes = $property->getAttributes(\Pocta\DataMapper\Attributes\MapDateTimeProperty::class);
                $propertyAttributes = $property->getAttributes(\Pocta\DataMapper\Attributes\MapProperty::class);

                $typeName = $this->getPropertyType($property, $dateTimeAttributes, $propertyAttributes);
                $format = $this->getFormatFromAttributes($dateTimeAttributes);
                $timezone = $this->getTimezoneFromAttributes($dateTimeAttributes);
                $arrayOf = $this->getArrayOfFromAttributes($dateTimeAttributes, $propertyAttributes);
                $classType = $this->getClassTypeFromAttributes($propertyAttributes);

                // Denormalize the value
                $type = $this->typeResolver->getType($typeName, $format, $timezone, $arrayOf, $classType);
                $isNullable = $property->getType()?->allowsNull() ?? true;
                $typedValue = $type->denormalize($value, $key, $isNullable);

                // Set the value
                $property->setValue($target, $typedValue);
            }

            return $target;
        } finally {
            $this->profiler?->stop('merge');
        }
    }

    /**
     * Finds a property by its JSON key (considering MapProperty attributes).
     *
     * @param \ReflectionClass<object> $reflection
     * @param string $jsonKey
     *
     * @return \ReflectionProperty|null
     */
    private function findPropertyByJsonKey(\ReflectionClass $reflection, string $jsonKey): ?\ReflectionProperty
    {
        foreach ($reflection->getProperties() as $property) {
            if (!PropertyVisibilityResolver::isPropertyKnownForInput($property, $this->publicPropertiesOnly)) {
                continue;
            }

            // Check direct match
            if ($property->getName() === $jsonKey) {
                return $property;
            }

            // Check MapProperty name
            $propertyAttributes = $property->getAttributes(\Pocta\DataMapper\Attributes\MapProperty::class);
            if (!empty($propertyAttributes)) {
                $attr = $propertyAttributes[0]->newInstance();
                if ($attr->name === $jsonKey) {
                    return $property;
                }
            }

            // Check MapDateTimeProperty name
            $dateTimeAttributes = $property->getAttributes(\Pocta\DataMapper\Attributes\MapDateTimeProperty::class);
            if (!empty($dateTimeAttributes)) {
                $attr = $dateTimeAttributes[0]->newInstance();
                if ($attr->name === $jsonKey) {
                    return $property;
                }
            }
        }

        return null;
    }

    /**
     * Gets property type considering attributes.
     *
     * @param \ReflectionProperty $property
     * @param array<\ReflectionAttribute<\Pocta\DataMapper\Attributes\MapDateTimeProperty>> $dateTimeAttributes
     * @param array<\ReflectionAttribute<\Pocta\DataMapper\Attributes\MapProperty>> $propertyAttributes
     *
     * @return string
     */
    private function getPropertyType(
        \ReflectionProperty $property,
        array $dateTimeAttributes,
        array $propertyAttributes
    ): string {
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
        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        return 'string';
    }

    /**
     * @param array<\ReflectionAttribute<\Pocta\DataMapper\Attributes\MapDateTimeProperty>> $dateTimeAttributes
     */
    private function getFormatFromAttributes(array $dateTimeAttributes): ?string
    {
        if (empty($dateTimeAttributes)) {
            return null;
        }
        return $dateTimeAttributes[0]->newInstance()->format;
    }

    /**
     * @param array<\ReflectionAttribute<\Pocta\DataMapper\Attributes\MapDateTimeProperty>> $dateTimeAttributes
     */
    private function getTimezoneFromAttributes(array $dateTimeAttributes): ?string
    {
        if (empty($dateTimeAttributes)) {
            return null;
        }
        return $dateTimeAttributes[0]->newInstance()->timezone;
    }

    /**
     * @param array<\ReflectionAttribute<\Pocta\DataMapper\Attributes\MapDateTimeProperty>> $dateTimeAttributes
     * @param array<\ReflectionAttribute<\Pocta\DataMapper\Attributes\MapProperty>> $propertyAttributes
     * @return class-string|string|null
     */
    private function getArrayOfFromAttributes(array $dateTimeAttributes, array $propertyAttributes): ?string
    {
        if (!empty($dateTimeAttributes)) {
            $arrayOf = $dateTimeAttributes[0]->newInstance()->arrayOf;
            // Convert PropertyType or ArrayElementType enum to string value if needed
            if ($arrayOf instanceof \Pocta\DataMapper\Attributes\PropertyType) {
                return $arrayOf->value;
            }
            if ($arrayOf instanceof \Pocta\DataMapper\Attributes\ArrayElementType) {
                return $arrayOf->value;
            }
            return $arrayOf;
        }
        if (!empty($propertyAttributes)) {
            $arrayOf = $propertyAttributes[0]->newInstance()->arrayOf;
            // Convert PropertyType or ArrayElementType enum to string value if needed
            if ($arrayOf instanceof \Pocta\DataMapper\Attributes\PropertyType) {
                return $arrayOf->value;
            }
            if ($arrayOf instanceof \Pocta\DataMapper\Attributes\ArrayElementType) {
                return $arrayOf->value;
            }
            return $arrayOf;
        }
        return null;
    }

    /**
     * @param array<\ReflectionAttribute<\Pocta\DataMapper\Attributes\MapProperty>> $propertyAttributes
     * @return class-string|null
     */
    private function getClassTypeFromAttributes(array $propertyAttributes): ?string
    {
        if (empty($propertyAttributes)) {
            return null;
        }
        return $propertyAttributes[0]->newInstance()->classType;
    }

    /**
     * Maps a collection of arrays to a collection of objects.
     *
     * @template T of object
     *
     * @param array<mixed> $collection Array of associative arrays.
     * @param class-string<T> $className
     *
     * @return array<int, T> Array of objects.
     *
     * @throws InvalidInputException When an element is not an associative array.
     * @throws \Pocta\DataMapper\Exceptions\ValidationException
     */
    public function fromArrayCollection(array $collection, string $className): array
    {
        $this->profiler?->start('fromArrayCollection');
        $this->debugger?->logOperation('fromArrayCollection', ['count' => count($collection)], $className);

        try {
            $results = [];
            foreach ($collection as $index => $data) {
                if (!is_array($data) || ($data !== [] && array_is_list($data))) {
                    throw new InvalidInputException(sprintf(
                        'Collection element [%s] must be an associative array (object), %s given',
                        $index,
                        is_array($data) ? 'list' : get_debug_type($data)
                    ));
                }
                $results[] = $this->fromArray($data, $className);
            }
            return $results;
        } finally {
            $this->profiler?->stop('fromArrayCollection');
        }
    }

    /**
     * Maps a JSON array to a collection of objects.
     *
     * @template T of object
     *
     * @param string $json JSON array string.
     * @param class-string<T> $className
     *
     * @return array<int, T> Array of objects.
     *
     * @throws JsonException
     * @throws InvalidArgumentException
     * @throws \Pocta\DataMapper\Exceptions\ValidationException
     */
    public function fromJsonCollection(string $json, string $className): array
    {
        $this->profiler?->start('fromJsonCollection');
        $this->debugger?->logOperation('fromJsonCollection', $json, $className);

        try {
            $data = json_decode($json, true, $this->jsonDepth, JSON_THROW_ON_ERROR);

            if (!is_array($data) || !$this->jsonStartsWith($json, '[')) {
                throw new InvalidInputException('JSON must decode to an array (list)');
            }

            return $this->fromArrayCollection($data, $className);
        } finally {
            $this->profiler?->stop('fromJsonCollection');
        }
    }

    /**
     * Checks the first token of a JSON document, skipping JSON whitespace.
     */
    private function jsonStartsWith(string $json, string $token): bool
    {
        return str_starts_with(ltrim($json, " \t\n\r"), $token);
    }

    /**
     * Maps an object to JSON string.
     *
     * @param object $object
     *
     * @return string
     *
     * @throws JsonException
     */
    public function toJson(object $object): string
    {
        $this->profiler?->start('toJson');
        $this->debugger?->logOperation('toJson', $object);

        try {
            $data = $this->normalizer->normalize($object);
            return json_encode($data, JSON_THROW_ON_ERROR);
        } finally {
            $this->profiler?->stop('toJson');
        }
    }

    /**
     * Maps a collection of objects to JSON array string.
     *
     * @param array<int, object> $collection Array of objects.
     *
     * @return string JSON array string.
     *
     * @throws JsonException
     */
    public function toJsonCollection(array $collection): string
    {
        $this->profiler?->start('toJsonCollection');
        $this->debugger?->logOperation('toJsonCollection', ['count' => count($collection)]);

        try {
            $data = $this->toArrayCollection($collection);
            return json_encode($data, JSON_THROW_ON_ERROR);
        } finally {
            $this->profiler?->stop('toJsonCollection');
        }
    }

    /**
     * Maps an object to an associative array.
     *
     * @param object $object
     *
     * @return array<string, mixed>
     */
    public function toArray(object $object): array
    {
        $this->profiler?->start('toArray');
        $this->debugger?->logOperation('toArray', $object);

        try {
            // Dispatch PreNormalize event
            $preEvent = new PreNormalizeEvent($object);
            $this->eventDispatcher->dispatch($preEvent);
            $object = $preEvent->object;

            $this->profiler?->start('normalize');

            $data = $this->normalizer->normalize($object);

            $this->profiler?->stop('normalize');

            // Dispatch PostNormalize event
            $postEvent = new PostNormalizeEvent($data, $object);
            $this->eventDispatcher->dispatch($postEvent);

            return $postEvent->data;
        } finally {
            $this->profiler?->stop('toArray');
        }
    }

    /**
     * Maps a collection of objects to an array of associative arrays.
     *
     * @param array<int, object> $collection Array of objects.
     *
     * @return array<int, array<string, mixed>> Array of associative arrays.
     */
    public function toArrayCollection(array $collection): array
    {
        $this->profiler?->start('toArrayCollection');
        $this->debugger?->logOperation('toArrayCollection', ['count' => count($collection)]);

        try {
            $results = [];
            foreach ($collection as $object) {
                $results[] = $this->toArray($object);
            }
            return $results;
        } finally {
            $this->profiler?->stop('toArrayCollection');
        }
    }

    /**
     * Setup debug event listeners.
     */
    private function setupDebugEventListeners(): void
    {
        if (!$this->debugger) {
            return;
        }

        // Log all events
        $debugger = $this->debugger;

        $eventTypes = [
            PreDenormalizeEvent::class,
            PostDenormalizeEvent::class,
            PreNormalizeEvent::class,
            PostNormalizeEvent::class,
            DenormalizationErrorEvent::class,
            ValidationEvent::class,
        ];

        foreach ($eventTypes as $eventType) {
            $this->addEventListener($eventType, function (Events\EventInterface $event) use ($debugger) {
                $debugger->logEvent($event);
            }, -999); // Low priority to run after other listeners
        }
    }

    /**
     * Maps CSV string to a collection of objects.
     *
     * @template T of object
     *
     * @param string $csv CSV string with header row
     * @param class-string<T> $className
     * @param string $delimiter CSV delimiter (default: ',')
     * @param string $enclosure CSV enclosure character (default: '"')
     * @param string $escape CSV escape character (default: '\\')
     * @param bool $hasHeader Whether CSV has header row (default: true)
     *
     * @return array<int, T> Array of objects
     *
     * @throws \Pocta\DataMapper\Exceptions\ValidationException
     */
    public function fromCsv(
        string $csv,
        string $className,
        string $delimiter = ',',
        string $enclosure = '"',
        string $escape = '\\',
        bool $hasHeader = true
    ): array {
        $this->profiler?->start('fromCsv');
        $this->debugger?->logOperation('fromCsv', ['length' => strlen($csv)], $className);

        try {
            // Handle empty CSV
            if (trim($csv) === '') {
                return [];
            }

            // Parse CSV using stream to properly handle quoted newlines
            $stream = fopen('php://memory', 'r+');
            if ($stream === false) {
                throw new \RuntimeException('Failed to create memory stream');
            }
            fwrite($stream, $csv);
            rewind($stream);

            $data = [];
            while (($row = fgetcsv($stream, 0, $delimiter, $enclosure, $escape)) !== false) {
                // Skip empty rows
                $hasContent = false;
                foreach ($row as $cell) {
                    if ($cell !== null && $cell !== '') {
                        $hasContent = true;
                        break;
                    }
                }
                if ($hasContent) {
                    $data[] = $row;
                }
            }
            fclose($stream);

            if (empty($data)) {
                return [];
            }

            $headers = null;
            if ($hasHeader) {
                $headers = array_shift($data);
            }

            // Convert rows to associative arrays
            $result = [];
            foreach ($data as $row) {
                $rowData = $this->csvRowToArray($row, $headers, $className);
                $result[] = $this->fromArray($rowData, $className);
            }

            return $result;
        } finally {
            $this->profiler?->stop('fromCsv');
        }
    }

    /**
     * Maps CSV file to a collection of objects.
     *
     * @template T of object
     *
     * @param string $filePath Path to CSV file
     * @param class-string<T> $className
     * @param string $delimiter CSV delimiter (default: ',')
     * @param string $enclosure CSV enclosure character (default: '"')
     * @param string $escape CSV escape character (default: '\\')
     * @param bool $hasHeader Whether CSV has header row (default: true)
     *
     * @return array<int, T> Array of objects
     *
     * @throws \Pocta\DataMapper\Exceptions\ValidationException
     * @throws \InvalidArgumentException
     */
    public function fromCsvFile(
        string $filePath,
        string $className,
        string $delimiter = ',',
        string $enclosure = '"',
        string $escape = '\\',
        bool $hasHeader = true
    ): array {
        $this->profiler?->start('fromCsvFile');
        $this->debugger?->logOperation('fromCsvFile', $filePath, $className);

        try {
            if (!file_exists($filePath)) {
                throw new \InvalidArgumentException("CSV file not found: {$filePath}");
            }

            if (!is_readable($filePath)) {
                throw new \InvalidArgumentException("CSV file is not readable: {$filePath}");
            }

            $handle = fopen($filePath, 'r');
            if ($handle === false) {
                throw new \InvalidArgumentException("Failed to open CSV file: {$filePath}");
            }

            $headers = null;
            if ($hasHeader) {
                $headers = fgetcsv($handle, 0, $delimiter, $enclosure, $escape);
                if ($headers === false) {
                    fclose($handle);
                    return [];
                }
            }

            $result = [];
            while (($row = fgetcsv($handle, 0, $delimiter, $enclosure, $escape)) !== false) {
                // Skip empty rows
                if (empty(array_filter($row, function ($cell) {
                    return $cell !== null && $cell !== '';
                }))) {
                    continue;
                }

                $rowData = $this->csvRowToArray($row, $headers, $className);
                $result[] = $this->fromArray($rowData, $className);
            }

            fclose($handle);
            return $result;
        } finally {
            $this->profiler?->stop('fromCsvFile');
        }
    }

    /**
     * Converts a collection of objects to CSV string.
     *
     * @param array<int, object> $collection Array of objects
     * @param string $delimiter CSV delimiter (default: ',')
     * @param string $enclosure CSV enclosure character (default: '"')
     * @param string $escape CSV escape character (default: '\\')
     * @param bool $includeHeader Whether to include header row (default: true)
     *
     * @return string CSV string
     */
    public function toCsv(
        array $collection,
        string $delimiter = ',',
        string $enclosure = '"',
        string $escape = '\\',
        bool $includeHeader = true
    ): string {
        $this->profiler?->start('toCsv');
        $this->debugger?->logOperation('toCsv', ['count' => count($collection)]);

        // CSV is flat - write properties with a path to flat columns, not nested arrays
        $symmetricPropertyPaths = $this->normalizer->isSymmetricPropertyPaths();
        $this->normalizer->setSymmetricPropertyPaths(false);

        try {
            if (empty($collection)) {
                return '';
            }

            $output = fopen('php://temp', 'r+');
            if ($output === false) {
                throw new \RuntimeException('Failed to create temporary stream for CSV output');
            }

            // Get first object to determine headers
            $first = $this->toArray($collection[0]);
            $headers = array_keys($first);

            // Write header
            if ($includeHeader) {
                fputcsv($output, $headers, $delimiter, $enclosure, $escape);
            }

            // Write data
            foreach ($collection as $object) {
                $data = $this->toArray($object);
                $row = [];
                foreach ($headers as $header) {
                    $row[] = $data[$header] ?? '';
                }
                /** @phpstan-ignore-next-line argument.type - toArray returns mixed values which are valid for fputcsv */
                fputcsv($output, $row, $delimiter, $enclosure, $escape);
            }

            rewind($output);
            $csv = stream_get_contents($output);
            fclose($output);

            return $csv !== false ? $csv : '';
        } finally {
            $this->normalizer->setSymmetricPropertyPaths($symmetricPropertyPaths);
            $this->profiler?->stop('toCsv');
        }
    }

    /**
     * Converts CSV row to associative array based on class metadata.
     *
     * @param array<int, string|null> $row CSV row data
     * @param array<int, string|null>|null $headers CSV headers
     * @param class-string $className
     *
     * @return array<string, mixed>
     */
    private function csvRowToArray(array $row, ?array $headers, string $className): array
    {
        $metadata = $this->metadataFactory->getMetadata($className);
        $result = [];

        // Build mapping from CSV columns to property names
        $columnMapping = $this->buildCsvColumnMapping($metadata, $headers);

        // Map by header names if available
        if ($headers !== null) {
            foreach ($headers as $index => $header) {
                $propertyName = $columnMapping['byName'][$header] ?? $header;
                $result[$propertyName] = $row[$index] ?? null;
            }
        }

        // Map by explicit index mappings from MapCsvColumn attributes
        foreach ($columnMapping['byIndex'] as $index => $propertyName) {
            if (isset($row[$index])) {
                $result[$propertyName] = $row[$index];
            }
        }

        // Map by position if no header and no explicit mappings
        if ($headers === null && empty($columnMapping['byIndex'])) {
            // Use constructor parameter order for mapping
            $propertyNames = [];
            if ($metadata->constructor !== null) {
                $propertyNames = array_values(array_map(fn($p) => $p->name, $metadata->constructor->parameters));
            }

            foreach ($row as $index => $value) {
                if (isset($propertyNames[$index])) {
                    $result[$propertyNames[$index]] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Builds CSV column mapping from metadata.
     *
     * @param \Pocta\DataMapper\Cache\ClassMetadata $metadata
     * @param array<int, string|null>|null $headers
     *
     * @return array{byName: array<string, string>, byIndex: array<int, string>}
     */
    private function buildCsvColumnMapping($metadata, ?array $headers): array
    {
        $byName = [];
        $byIndex = [];

        // Check constructor parameters
        if ($metadata->constructor !== null) {
            foreach ($metadata->constructor->parameters as $param) {
                // Search through cached attributes array for MapCsvColumn
                foreach ($param->attributes as $attr) {
                    if ($attr instanceof \Pocta\DataMapper\Attributes\MapCsvColumn) {
                        if ($attr->name !== null) {
                            $byName[$attr->name] = $param->name;
                        }
                        if ($attr->index !== null) {
                            $byIndex[$attr->index] = $param->name;
                        }
                        break; // Only use first MapCsvColumn attribute
                    }
                }
            }
        }

        // Check properties
        foreach ($metadata->properties as $property) {
            // Search through cached attributes array for MapCsvColumn
            foreach ($property->attributes as $attr) {
                if ($attr instanceof \Pocta\DataMapper\Attributes\MapCsvColumn) {
                    if ($attr->name !== null) {
                        $byName[$attr->name] = $property->name;
                    }
                    if ($attr->index !== null) {
                        $byIndex[$attr->index] = $property->name;
                    }
                    break; // Only use first MapCsvColumn attribute
                }
            }
        }

        return ['byName' => $byName, 'byIndex' => $byIndex];
    }
}
