<?php

declare(strict_types=1);

namespace Pocta\DataMapper;

use Pocta\DataMapper\Attributes\Ignore;

/**
 * Implementation of ProvidedFieldsInterface for DTOs:
 *
 *   final class UpdateRequest implements ProvidedFieldsInterface
 *   {
 *       use ProvidedFields;
 *
 *       public ?string $note = null;
 *   }
 *
 *   if ($request->isProvided('note')) { ... }   // sent - possibly null (= clear the value)
 *
 * The tracking itself is excluded from mapping (#[Ignore]): it is never written to output nor
 * hydrated from input.
 */
trait ProvidedFields
{
    /** @var list<string> */
    #[Ignore]
    private array $providedFields = [];

    /**
     * @param list<string> $properties
     */
    public function markProvidedFields(array $properties): void
    {
        $this->providedFields = array_values(array_unique([...$this->providedFields, ...$properties]));
    }

    public function isProvided(string $property): bool
    {
        return in_array($property, $this->providedFields, true);
    }

    /**
     * @return list<string>
     */
    public function getProvidedFields(): array
    {
        return $this->providedFields;
    }
}
