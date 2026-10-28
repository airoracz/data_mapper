<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Normalizer;

use Pocta\DataMapper\Attributes\MappingAttributes;
use Pocta\DataMapper\PropertyPathResolver;
use Pocta\DataMapper\PropertyVisibilityResolver;
use Pocta\DataMapper\Types\TypeResolver;
use ReflectionClass;
use ReflectionProperty;

class Normalizer
{
    private TypeResolver $typeResolver;

    /**
     * Stack of objects currently being normalized (for cycle detection).
     *
     * @var array<int, int>
     */
    private array $objectStack = [];

    private bool $publicPropertiesOnly = false;

    private bool $symmetricPropertyPaths = false;

    /**
     * Per-class normalization plan: attribute-derived settings and filter instances, resolved once.
     * Settings that can change at runtime (public-only, symmetric paths, skip nulls) are applied per call.
     *
     * @var array<class-string, list<array{
     *     property: ReflectionProperty,
     *     jsonKey: string,
     *     path: ?string,
     *     typeName: string,
     *     arrayOf: ?string,
     *     classType: ?class-string,
     *     outputFormat: ?string,
     *     filters: \Pocta\DataMapper\Attributes\Filters\FilterChain,
     *     normalizable: bool,
     *     normalizableWhenPublicOnly: bool
     * }>>
     */
    private array $plans = [];

    public function __construct(
        ?TypeResolver $typeResolver = null,
        /** @phpstan-ignore-next-line property.onlyWritten */
        private ?\Pocta\DataMapper\Cache\ClassMetadataFactory $metadataFactory = null,
        private bool $skipNullValues = false
    ) {
        $this->typeResolver = $typeResolver ?? new TypeResolver();
    }

    /**
     * Enable or disable skipping null values in output.
     */
    public function setSkipNullValues(bool $skipNullValues): void
    {
        $this->skipNullValues = $skipNullValues;
    }

    /**
     * Enable or disable writing properties with a `path` back into the same nested structure.
     */
    public function setSymmetricPropertyPaths(bool $symmetricPropertyPaths): void
    {
        $this->symmetricPropertyPaths = $symmetricPropertyPaths;
    }

    /**
     * Check if properties with a `path` are written back into the nested structure.
     */
    public function isSymmetricPropertyPaths(): bool
    {
        return $this->symmetricPropertyPaths;
    }

    /**
     * Enable or disable normalization of public properties only.
     */
    public function setPublicPropertiesOnly(bool $publicPropertiesOnly): void
    {
        $this->publicPropertiesOnly = $publicPropertiesOnly;
    }

    /**
     * Converts an object to an associative array.
     *
     * @param object $object
     *
     * @return array<string, mixed>
     *
     * @throws \Pocta\DataMapper\Exceptions\CircularReferenceException
     * @throws \InvalidArgumentException When a mapping attribute on the class is misconfigured.
     */
    public function normalize(object $object): array
    {
        // Detect circular references
        $objectHash = spl_object_id($object);
        if (in_array($objectHash, $this->objectStack, true)) {
            throw new \Pocta\DataMapper\Exceptions\CircularReferenceException(
                sprintf(
                    'Circular reference detected for object of class "%s"',
                    get_class($object)
                )
            );
        }

        // Add to stack
        $this->objectStack[] = $objectHash;

        try {
            $data = [];

            foreach ($this->plans[$object::class] ?? $this->buildPlan($object) as $field) {
                if (!($this->publicPropertiesOnly ? $field['normalizableWhenPublicOnly'] : $field['normalizable'])) {
                    continue;
                }

                $value = $this->getPropertyValue($field['property'], $object);

                if ($value !== null || !$this->skipNullValues) {
                    $normalizedValue = $this->normalizeValue(
                        $value,
                        $field['typeName'],
                        $field['arrayOf'],
                        $field['classType'],
                        $field['outputFormat']
                    );
                    // Apply post-normalization filters, in declaration order
                    $normalizedValue = $field['filters']->apply($normalizedValue);
                    if ($this->symmetricPropertyPaths) {
                        $this->writeOutputValue($data, $field['property'], $field['jsonKey'], $field['path'], $normalizedValue);
                    } else {
                        $data[$field['jsonKey']] = $normalizedValue;
                    }
                }
            }

            return $data;
        } finally {
            // Remove from stack
            array_pop($this->objectStack);
        }
    }

    /**
     * Writes a value to its key or path, refusing to overwrite a value written by another property.
     * With symmetric paths, a plain key and a path can target the same output location
     * (e.g. $meta and path "meta.source"); silently overwriting would drop a field.
     *
     * @param array<string, mixed> $data
     *
     * @throws \LogicException When the target is already taken by another property.
     */
    private function writeOutputValue(array &$data, ReflectionProperty $property, string $jsonKey, ?string $path, mixed $value): void
    {
        $target = $path ?? $jsonKey;
        $collision = sprintf(
            'Cannot normalize %s::$%s: output location "%s" collides with another property',
            $property->getDeclaringClass()->getName(),
            $property->getName(),
            $target
        );

        if ($path === null) {
            if (array_key_exists($jsonKey, $data)) {
                throw new \LogicException($collision);
            }
            $data[$jsonKey] = $value;
            return;
        }

        if (PropertyPathResolver::exists($data, $path)) {
            throw new \LogicException($collision);
        }

        try {
            PropertyPathResolver::set($data, $path, $value);
        } catch (\InvalidArgumentException $e) {
            throw new \LogicException($collision . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Gets the value of a property.
     *
     * @param ReflectionProperty $property
     * @param object $object
     *
     * @return mixed
     */
    private function getPropertyValue(ReflectionProperty $property, object $object): mixed
    {
        // Check if property is initialized to avoid accessing uninitialized typed properties
        if (!$property->isInitialized($object)) {
            return null;
        }

        return $property->getValue($object);
    }

    /**
     * Normalizes a value using the appropriate type handler.
     *
     * @param mixed $value
     * @param string $typeName
     * @param string|null $arrayOf Class name or scalar type name
     * @param class-string|null $classType
     * @param string|null $outputFormat Custom output format (for DateTime types)
     *
     * @return mixed
     */
    private function normalizeValue(mixed $value, string $typeName, ?string $arrayOf = null, ?string $classType = null, ?string $outputFormat = null): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $this->typeResolver->getType($typeName, null, null, $arrayOf, $classType, $outputFormat);
        return $type->normalize($value);
    }

    /**
     * Builds and caches the normalization plan for the object's class.
     *
     * All attributes are instantiated here (as before, a misconfigured attribute throws);
     * if building fails nothing is cached, so the error repeats on every call.
     * Built-in filter instances are reused; user-defined filters are instantiated per call (see FilterChain).
     *
     * @return list<array{
     *     property: ReflectionProperty,
     *     jsonKey: string,
     *     path: ?string,
     *     typeName: string,
     *     arrayOf: ?string,
     *     classType: ?class-string,
     *     outputFormat: ?string,
     *     filters: \Pocta\DataMapper\Attributes\Filters\FilterChain,
     *     normalizable: bool,
     *     normalizableWhenPublicOnly: bool
     * }>
     */
    private function buildPlan(object $object): array
    {
        $plan = [];

        foreach ((new ReflectionClass($object))->getProperties() as $property) {
            $filters = \Pocta\DataMapper\Attributes\Filters\FilterChain::fromAttributes($property->getAttributes());

            $mapping = MappingAttributes::of($property);
            $plan[] = [
                'property' => $property,
                'jsonKey' => $mapping->jsonKey(),
                'path' => $mapping->path(),
                'typeName' => $mapping->typeName(),
                'arrayOf' => $mapping->arrayOf(),
                'classType' => $mapping->classType(),
                'outputFormat' => $mapping->outputFormat(),
                'filters' => $filters,
                'normalizable' => PropertyVisibilityResolver::isPropertyNormalizable($property, false),
                'normalizableWhenPublicOnly' => PropertyVisibilityResolver::isPropertyNormalizable($property, true),
            ];
        }

        return $this->plans[$object::class] = $plan;
    }
}
