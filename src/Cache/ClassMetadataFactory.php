<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Cache;

use Pocta\DataMapper\Attributes\MappingAttributes;
use Pocta\DataMapper\Attributes\MapFrom;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Factory for building ClassMetadata from reflection
 * Uses cache to avoid repeated reflection calls
 */
class ClassMetadataFactory
{
    private CacheInterface $cache;

    /**
     * In-process memo in front of the cache backend - a file or Redis backend would otherwise be
     * read (and unserialized) on every call, i.e. per object in fromObject() and per CSV row.
     *
     * @var array<string, ClassMetadata>
     */
    private array $memo = [];

    public function __construct(?CacheInterface $cache = null)
    {
        $this->cache = $cache ?? new ArrayCache();
    }

    /**
     * Get or build metadata for a class
     *
     * @template T of object
     * @param class-string<T> $className
     * @return ClassMetadata
     */
    public function getMetadata(string $className): ClassMetadata
    {
        if (isset($this->memo[$className])) {
            return $this->memo[$className];
        }

        $cacheKey = 'class_metadata:' . $className;

        // Try to get from cache
        $cached = $this->cache->get($cacheKey);
        if ($cached instanceof ClassMetadata) {
            return $this->memo[$className] = $cached;
        }

        // Build from reflection
        $metadata = $this->buildMetadata($className);

        // Store in cache
        $this->cache->set($cacheKey, $metadata);

        return $this->memo[$className] = $metadata;
    }

    /**
     * Clear cache for specific class or all classes
     *
     * @param class-string|null $className
     */
    public function clearCache(?string $className = null): void
    {
        if ($className === null) {
            $this->memo = [];
            $this->cache->clear();
        } else {
            unset($this->memo[$className]);
            $this->cache->delete('class_metadata:' . $className);
        }
    }

    /**
     * Build metadata from reflection
     *
     * @template T of object
     * @param class-string<T> $className
     * @return ClassMetadata
     */
    private function buildMetadata(string $className): ClassMetadata
    {
        $reflection = new ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        // Build properties metadata
        $properties = [];
        foreach ($reflection->getProperties() as $property) {
            $propertyMeta = $this->buildPropertyMetadata($property);
            $properties[$property->getName()] = $propertyMeta;
        }

        // Build constructor metadata
        $constructorMeta = null;
        if ($constructor && $constructor->getNumberOfParameters() > 0) {
            $parameters = [];
            foreach ($constructor->getParameters() as $parameter) {
                $paramMeta = $this->buildParameterMetadata($parameter);
                $parameters[$parameter->getName()] = $paramMeta;
            }
            $constructorMeta = new ConstructorMetadata($parameters);
        }

        return new ClassMetadata($className, $properties, $constructorMeta);
    }

    /**
     * Build metadata for a property
     */
    private function buildPropertyMetadata(ReflectionProperty $property): PropertyMetadata
    {
        $mapping = MappingAttributes::of($property);
        $jsonKey = $mapping->jsonKey();
        $typeName = $mapping->typeName();
        $isNullable = $property->getType()?->allowsNull() ?? true;

        // Check if promoted (exists in constructor parameters)
        $isPromoted = $property->isPromoted();

        // Get all attributes as objects
        $attributes = [];
        foreach ($property->getAttributes() as $attr) {
            $attributes[] = $attr->newInstance();
        }

        $format = $mapping->format();
        $timezone = $mapping->timezone();
        $arrayOf = $mapping->arrayOf();
        $classType = $mapping->classType();
        $mapFrom = $this->getMapFrom($property);

        return new PropertyMetadata(
            $property->getName(),
            $jsonKey,
            $typeName,
            $isNullable,
            $isPromoted,
            $attributes,
            $format,
            $timezone,
            $arrayOf,
            $classType,
            $mapFrom
        );
    }

    /**
     * Build metadata for a constructor parameter
     */
    private function buildParameterMetadata(ReflectionParameter $parameter): ParameterMetadata
    {
        $mapping = MappingAttributes::of($parameter);
        $jsonKey = $mapping->jsonKey();
        $typeName = $mapping->typeName();
        $isNullable = $parameter->allowsNull();
        $hasDefaultValue = $parameter->isDefaultValueAvailable();
        $defaultValue = $hasDefaultValue ? $parameter->getDefaultValue() : null;

        // Get all attributes as objects
        $attributes = [];
        foreach ($parameter->getAttributes() as $attr) {
            $attributes[] = $attr->newInstance();
        }

        $format = $mapping->format();
        $timezone = $mapping->timezone();
        $arrayOf = $mapping->arrayOf();
        $classType = $mapping->classType();
        $mapFrom = $this->getMapFromParam($parameter);

        return new ParameterMetadata(
            $parameter->getName(),
            $jsonKey,
            $typeName,
            $isNullable,
            $hasDefaultValue,
            $defaultValue,
            $attributes,
            $format,
            $timezone,
            $arrayOf,
            $classType,
            $mapFrom
        );
    }

    /**
     * Get MapFrom attribute from property
     */
    private function getMapFrom(ReflectionProperty $property): ?MapFrom
    {
        $attrs = $property->getAttributes(MapFrom::class);
        if (empty($attrs)) {
            return null;
        }
        return $attrs[0]->newInstance();
    }

    /**
     * Get MapFrom attribute from parameter
     */
    private function getMapFromParam(ReflectionParameter $parameter): ?MapFrom
    {
        $attrs = $parameter->getAttributes(MapFrom::class);
        if (empty($attrs)) {
            return null;
        }
        return $attrs[0]->newInstance();
    }
}
