<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Attributes\Filters;

use ReflectionAttribute;

/**
 * Filters of one property/parameter, in declaration order, prepared for repeated use.
 *
 * Built-in filters (this namespace) have side-effect-free constructors, so one instance is reused.
 * User-defined filters may depend on construction time or state (e.g. `new DateTimeImmutable()`
 * as an attribute argument), so they are instantiated on every apply - as before caching.
 *
 * @internal
 */
final class FilterChain
{
    /**
     * @param list<FilterInterface|ReflectionAttribute<object>> $filters Instance (reused) or attribute (instantiated per apply)
     */
    private function __construct(private readonly array $filters)
    {
    }

    /**
     * Instantiates every attribute once, so a misconfigured attribute throws here exactly as before.
     *
     * @param array<ReflectionAttribute<object>> $attributes
     */
    public static function fromAttributes(array $attributes): self
    {
        $filters = [];
        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();
            if (!$instance instanceof FilterInterface) {
                continue;
            }
            $filters[] = $instance::class === $attribute->getName() && str_starts_with($attribute->getName(), __NAMESPACE__ . '\\')
                ? $instance
                : $attribute;
        }

        return new self($filters);
    }

    public function apply(mixed $value): mixed
    {
        foreach ($this->filters as $filter) {
            $instance = $filter instanceof ReflectionAttribute ? $filter->newInstance() : $filter;
            if ($instance instanceof FilterInterface) {
                $value = $instance->apply($value);
            }
        }

        return $value;
    }
}
