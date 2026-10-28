<?php

declare(strict_types=1);

namespace Pocta\DataMapper;

/**
 * Objects that want to know which fields were present in the input (PATCH semantics:
 * "not sent" vs "sent as null"). Implement it with the ProvidedFields trait.
 *
 * The mapper calls markProvidedFields() after denormalization (and merge()) with the names of the
 * properties whose key (or path) was present in the input data.
 */
interface ProvidedFieldsInterface
{
    /**
     * @param list<string> $properties Property names present in the input
     */
    public function markProvidedFields(array $properties): void;

    /**
     * Whether the property was present in the input (also when its value was null).
     */
    public function isProvided(string $property): bool;

    /**
     * @return list<string> Names of the properties present in the input, in declaration order
     */
    public function getProvidedFields(): array;
}
