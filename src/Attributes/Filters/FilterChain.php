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

    /**
     * Applies all filters once (normalization).
     */
    public function apply(mixed $value): mixed
    {
        foreach ($this->filters as $filter) {
            $value = $this->instantiate($filter)->apply($value);
        }

        return $value;
    }

    /**
     * First pass of denormalization, on the raw input value. Records the filters that changed
     * the value - they have taken effect and must not run again after type conversion.
     *
     * @param list<int> $applied Indexes of filters that changed the value (output)
     * @param-out list<int> $applied
     */
    public function applyBeforeTyping(mixed $value, ?array &$applied): mixed
    {
        $applied = [];
        foreach ($this->filters as $index => $filter) {
            $filtered = $this->instantiate($filter)->apply($value);
            if ($filtered !== $value) {
                $applied[] = $index;
            }
            $value = $filtered;
        }

        return $value;
    }

    /**
     * Second pass of denormalization, on the typed value: only filters that had no effect on the
     * raw input (e.g. a date filter on a date string) run, so every filter takes effect once.
     *
     * @param list<int> $applied Indexes returned by applyBeforeTyping()
     */
    public function applyAfterTyping(mixed $value, array $applied): mixed
    {
        foreach ($this->filters as $index => $filter) {
            if (!in_array($index, $applied, true)) {
                $value = $this->instantiate($filter)->apply($value);
            }
        }

        return $value;
    }

    /**
     * @param FilterInterface|ReflectionAttribute<object> $filter
     */
    private function instantiate(FilterInterface|ReflectionAttribute $filter): FilterInterface
    {
        if ($filter instanceof FilterInterface) {
            return $filter;
        }

        $instance = $filter->newInstance();
        if (!$instance instanceof FilterInterface) {
            throw new \LogicException(sprintf('Attribute "%s" is not a filter', $filter->getName()));
        }

        return $instance;
    }
}
