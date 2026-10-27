# Configuration (MapperOptions)

[← Back to README](../README.md)

The `MapperOptions` class provides a clean way to configure the Mapper behavior.

## Available Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `autoValidate` | `bool` | `false` | Automatically validate objects after denormalization |
| `strictMode` | `bool` | `false` | Throw validation error if unknown keys are present in input |
| `throwOnMissingData` | `bool` | `true` | Throw exception when required data is missing |
| `skipNullValues` | `bool` | `false` | Skip null values during normalization (don't include in output) |
| `preserveNumericStrings` | `bool` | `false` | Keep numeric strings as strings instead of converting to numbers |
| `publicPropertiesOnly` | `bool` | `false` | Map only public properties (see [Property Visibility](#property-visibility)) |
| `jsonDepth` | `int` | `512` | Maximum nesting depth for `fromJson()` / `fromJsonCollection()`; deeper input throws `JsonException` |
| `symmetricPropertyPaths` | `bool` | `false` | Normalize properties with `path` back into the same nested structure (see [advanced](advanced.md#important-notes)) |

## Factory Methods

```php
// Quick configurations for common scenarios
MapperOptions::withAutoValidation()  // Enable auto-validation only
MapperOptions::withStrictMode()      // Enable strict mode only
MapperOptions::strict()              // Enable both auto-validation and strict mode
MapperOptions::development()         // Strict validation for development
MapperOptions::production()          // Lenient configuration for production
```

## Custom Configuration

```php
$options = new MapperOptions(
    autoValidate: true,
    strictMode: true,
    skipNullValues: true
);

$mapper = new Mapper($options);
```

## Immutable Updates

Use the `with()` method to create modified copies:

```php
$baseOptions = MapperOptions::production();
$strictOptions = $baseOptions->with(strictMode: true);

// $baseOptions is unchanged
// $strictOptions has strictMode enabled
```

## Recommended Configuration for HTTP APIs

For mappers that process untrusted request bodies:

```php
$mapper = new Mapper(new MapperOptions(
    autoValidate: true,
    strictMode: true,             // unknown keys (typos, extra fields) → ValidationException, also in nested objects
    throwOnMissingData: true,     // default - keep it
    publicPropertiesOnly: true,   // no private/protected data in or out
    symmetricPropertyPaths: true, // request and response share one shape
    jsonDepth: 64,
));
```

> **Do not disable `throwOnMissingData` for API input.** With `false`, a typed property without a default
> that is missing from input stays uninitialized; validation only reports it when the property carries a
> constraint (e.g. `#[NotBlank]`). The first read then fails with
> `Error: must not be accessed before initialization` (HTTP 500 instead of 422).

## Property Visibility

Static properties are never mapped - they are class-level state, not object data.

By default, all instance properties are mapped regardless of visibility. That means `private`
and `protected` properties are written to the output and can be set from input data. For DTOs
bound to an HTTP API this leaks internal data (password hashes, tokens) and allows mass
assignment of fields outside the public contract (`role`, `isAdmin`, `ownerId`).

Enable `publicPropertiesOnly` to map only public properties:

```php
$mapper = new Mapper(new MapperOptions(publicPropertiesOnly: true));

class UserDto
{
    public string $name;

    private string $passwordHash = '';     // never serialized, never hydrated

    #[MapProperty(name: 'owner_id')]
    private ?int $ownerId = null;          // explicitly opted in - mapped both ways

    public function __construct(
        string $name,
        private string $role = 'user',     // promoted, non-public: input 'role' is ignored, default used
    ) {
        $this->name = $name;
    }
}
```

Rules in public-only mode:

- Non-public properties are mapped only when they carry `#[MapProperty]` or `#[MapDateTimeProperty]`.
- Non-promoted constructor parameters are always hydrated - they are the constructor's explicit API.
- A non-public promoted parameter without the attribute gets its default value (or `null` when nullable).
  If it has neither, the class cannot be built and a `LogicException` is thrown - this is a
  configuration error, not invalid input.
- In strict mode, keys of non-mappable properties are reported as unknown.
- `merge()` follows the same rules.

> In 1.x the option defaults to `false` for backward compatibility. Enable it for any mapper that
> processes untrusted input.

### Ignore, OutputOnly, InputOnly

Per-property control of mapping direction, independent of visibility:

| Attribute | Output (`toArray`/`toJson`) | Input (`fromArray`/`fromJson`/`merge`) |
|-----------|-----------------------------|----------------------------------------|
| `#[Ignore]` | skipped | skipped |
| `#[OutputOnly]` | written | skipped |
| `#[InputOnly]` | skipped | hydrated |

```php
use Pocta\DataMapper\Attributes\{Ignore, InputOnly, OutputOnly};

class UserDto
{
    public string $name;

    #[OutputOnly]
    public string $role = 'user';      // client cannot set it

    #[InputOnly]
    public ?string $password = null;   // never leaves the server

    #[Ignore]
    public array $cache = [];          // not part of the contract at all
}
```

- These attributes always win over `#[MapProperty]` opt-in in public-only mode.
- In strict mode, a key of a property skipped on input is reported as unknown.
- On a promoted constructor parameter skipped on input, the default value (or `null`) is used;
  a required one throws `LogicException`.

## Supported Types

### Scalar Types
- `int` / `integer` - Integers
- `float` / `double` - Floating-point numbers
- `string` - Text strings
- `bool` / `boolean` - Boolean values

### Date and Time
- `DateTimeImmutable` - Immutable date/time object (recommended)
- `DateTime` - Mutable date/time object
- Input format support: ISO 8601, RFC 3339, MySQL datetime, custom formats via `format` parameter
- Output format: Configurable via `outputFormat` parameter (default: ISO 8601 with microseconds)
- Timezones: Automatic conversion between timezones

### Enum (PHP 8.1+)
- `BackedEnum` - Enum with values (string or int)
- `UnitEnum` - Simple enum without values

### Complex Types
- `array` - Array with arbitrary content
- `array<int>`, `array<string>`, `array<float>`, `array<bool>` - Array of scalars using `arrayOf` attribute (use `ArrayElementType` enum)
- `array<ClassName>` - Array of objects using `arrayOf` attribute
- Custom objects - Nested objects of arbitrary depth

### Nullable Types
All types support nullable variants:
- `?int`, `?string`, `?bool`
- `?DateTimeImmutable`, `?DateTime`
- `?MyCustomClass`
