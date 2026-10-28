<?php

declare(strict_types=1);

namespace Pocta\DataMapper;

class MapperOptions
{
    /**
     * @param bool $autoValidate Automatically validate objects after denormalization
     * @param bool $strictMode Throw validation error if unknown keys are present in input data
     * @param bool $throwOnMissingData Throw exception when required data is missing (default: true)
     * @param bool $skipNullValues Skip null values during normalization (don't include them in output)
     * @param bool $preserveNumericStrings Keep numeric strings as strings instead of converting to numbers
     * @param bool $publicPropertiesOnly Map only public properties; non-public ones only with #[MapProperty] or #[MapDateTimeProperty]
     * @param bool $symmetricPropertyPaths Normalize properties with a `path` back into the same nested structure (instead of a flat key)
     * @param int $jsonDepth Maximum nesting depth when decoding JSON input (fromJson, fromJsonCollection)
     * @param bool $parseFormattedNumbers Accept formatted numeric strings for int/float ("10 000", "1 234,50")
     */
    public function __construct(
        public readonly bool $autoValidate = false,
        public readonly bool $strictMode = false,
        public readonly bool $throwOnMissingData = true,
        public readonly bool $skipNullValues = false,
        public readonly bool $preserveNumericStrings = false,
        public readonly bool $publicPropertiesOnly = false,
        public readonly bool $symmetricPropertyPaths = false,
        public readonly int $jsonDepth = 512,
        public readonly bool $parseFormattedNumbers = false,
    ) {
        if ($jsonDepth < 1) {
            throw new \InvalidArgumentException(sprintf('jsonDepth must be at least 1, %d given', $jsonDepth));
        }
    }

    /**
     * Create options with auto-validation enabled
     */
    public static function withAutoValidation(): self
    {
        return new self(autoValidate: true);
    }

    /**
     * Create options with strict mode enabled
     */
    public static function withStrictMode(): self
    {
        return new self(strictMode: true);
    }

    /**
     * Create options with both auto-validation and strict mode enabled
     */
    public static function strict(): self
    {
        return new self(autoValidate: true, strictMode: true);
    }

    /**
     * Create options for development (strict + auto-validation + throw on missing)
     */
    public static function development(): self
    {
        return new self(
            autoValidate: true,
            strictMode: true,
            throwOnMissingData: true
        );
    }

    /**
     * Create options for production (lenient, no auto-validation)
     */
    public static function production(): self
    {
        return new self(
            autoValidate: false,
            strictMode: false,
            throwOnMissingData: true
        );
    }

    /**
     * Create a modified copy with updated values
     */
    public function with(
        ?bool $autoValidate = null,
        ?bool $strictMode = null,
        ?bool $throwOnMissingData = null,
        ?bool $skipNullValues = null,
        ?bool $preserveNumericStrings = null,
        ?bool $publicPropertiesOnly = null,
        ?bool $symmetricPropertyPaths = null,
        ?int $jsonDepth = null,
        ?bool $parseFormattedNumbers = null,
    ): self {
        return new self(
            autoValidate: $autoValidate ?? $this->autoValidate,
            strictMode: $strictMode ?? $this->strictMode,
            throwOnMissingData: $throwOnMissingData ?? $this->throwOnMissingData,
            skipNullValues: $skipNullValues ?? $this->skipNullValues,
            preserveNumericStrings: $preserveNumericStrings ?? $this->preserveNumericStrings,
            publicPropertiesOnly: $publicPropertiesOnly ?? $this->publicPropertiesOnly,
            symmetricPropertyPaths: $symmetricPropertyPaths ?? $this->symmetricPropertyPaths,
            jsonDepth: $jsonDepth ?? $this->jsonDepth,
            parseFormattedNumbers: $parseFormattedNumbers ?? $this->parseFormattedNumbers,
        );
    }
}
