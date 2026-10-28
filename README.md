# Data Mapper

[![CI](https://github.com/igorpocta/data_mapper/actions/workflows/ci.yml/badge.svg)](https://github.com/igorpocta/data-mapper/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-level%209-brightgreen)](https://phpstan.org)

High-performance and type-safe PHP library for bidirectional data mapping between JSON/arrays/CSV and objects.

## Requirements

- PHP 8.1 or higher
- Composer

## Installation

```bash
composer require igorpocta/data-mapper
```

## Quick Start

```php
use Pocta\DataMapper\Mapper;

class User
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $active
    ) {}
}

$mapper = new Mapper();

// JSON → Object
$user = $mapper->fromJson('{"id": 1, "name": "John", "active": true}', User::class);

// Object → JSON
$json = $mapper->toJson($user); // {"id":1,"name":"John","active":true}

// Array → Object
$user = $mapper->fromArray(['id' => 1, 'name' => 'John', 'active' => true], User::class);

// Object → Array
$array = $mapper->toArray($user); // ['id' => 1, 'name' => 'John', 'active' => true]
```

### Configuration

```php
use Pocta\DataMapper\MapperOptions;

$mapper = new Mapper(MapperOptions::api());        // Recommended for HTTP request bodies (untrusted input)
$mapper = new Mapper(MapperOptions::strict());     // Auto-validation + unknown keys rejected
$mapper = new Mapper(MapperOptions::production()); // Defaults (lenient)

// Presets are adjustable
$mapper = new Mapper(MapperOptions::api()->with(parseFormattedNumbers: true));
```

See [Configuration](docs/configuration.md) for all options.

## Key Features

### Mapping
- **Bidirectional mapping**: JSON/array/CSV ↔ objects with automatic conversion
- **CSV Support**: Import/export CSV files with automatic type conversion and custom column mapping
- **Batch processing**: Efficient mapping of collections with `fromArrayCollection()`, `toJsonCollection()`, `fromCsv()`, etc.
- **Property path resolver**: Map nested values using dot notation (e.g., `user.address.street`)
- **Type safety**: Typed properties and constructor parameters drive conversion (union types are mapped as
  `string` - set the type explicitly with `#[MapProperty(type: ...)]`)

### Data Types
- **Basic types**: int, float, string, bool, array
- **DateTime**: DateTimeImmutable and DateTime with formats and timezones
- **Enum**: BackedEnum and UnitEnum (PHP 8.1+)
- **Objects**: Nested objects and arrays of objects

### Security (untrusted input)
- **Strict mode**: Unknown keys (typos, extra fields) are rejected, also in nested objects
- **Public properties only**: No private/protected data in output, no mass assignment from input
- **Direction control**: `#[Ignore]`, `#[OutputOnly]` (e.g. `role`, `id`), `#[InputOnly]` (e.g. `password`)
- **Input shape checks**: JSON depth limit, `InvalidInputException` for scalars/lists where an object is expected

### Advanced
- **Constructor properties**: Full support for promoted properties
- **Partial updates**: Merge partial data into existing objects with `merge()`
- **Object-to-DTO mapping**: Map from Doctrine entities to DTOs using `fromObject()`
- **Discriminator mapping**: Polymorphic object mapping based on discriminator fields
- **Filters**: 70+ built-in filters (security, formatting, case conversion, etc.)
- **Hydration**: Custom functions for data transformation using `MapPropertyWithFunction`
- **Event System**: Hooks for pre/post processing (logging, transformations, error handling)
- **Validation**: 40+ Assert attributes (NotNull, Range, Email, Count, Valid, Callback, etc.)
- **Validation Groups**: Conditional validation with `GroupSequenceProviderInterface`
- **Recursive Validation**: `#[Valid]` for nested objects with dot-notation error paths

### Code Quality
- **PHPStan Level 9**: Strictest static analysis, checked on PHP 8.1 - 8.5
- **Extensive test suite** run on PHP 8.1 - 8.5
- **Zero external dependencies**
- **Debug & Profiling**: Integrated tools for performance analysis

## Attributes

| Attribute | Purpose |
|-----------|---------|
| `#[MapProperty]` | Custom key (`name`), nested `path`, explicit `type`, `arrayOf`, `classType` |
| `#[MapDateTimeProperty]` | Input `format`, `outputFormat`, `timezone` for date/time values (plus `name`/`path`) |
| `#[MapFrom]` | Source path/getter for `fromObject()` (entity → DTO) |
| `#[MapCsvColumn]` | CSV column by `name` or `index` |
| `#[MapPropertyWithFunction]` | Compute the value with a callable (`HydrationMode::VALUE/PARENT/FULL`) |
| `#[DiscriminatorMap]` | Polymorphic mapping by a discriminator field |
| `#[Ignore]` / `#[OutputOnly]` / `#[InputOnly]` | Exclude a property from both directions / from input / from output |
| Filters (`#[StringTrimFilter]`, ...) | Transform values during mapping - see [Advanced Features](docs/advanced.md) |
| Validators (`#[NotBlank]`, `#[Valid]`, ...) | Validation rules - see [Validation](docs/validation.md) |

## Documentation

| Topic | Description |
|-------|-------------|
| [Configuration](docs/configuration.md) | MapperOptions, supported types |
| [Usage](docs/usage.md) | Basic usage, batch processing, CSV, merge, Object-to-DTO, discriminator, class definitions |
| [Advanced Features](docs/advanced.md) | Property paths, filters, hydration, nullable, custom names, type conversion |
| [Event System](docs/events.md) | Event listeners, priorities, practical examples |
| [Validation](docs/validation.md) | Validators, groups, recursive validation, callback, custom validators |
| [Cache System](docs/cache.md) | Array, File, Redis, custom cache implementations |
| [Debug & Profiling](docs/debugging.md) | Debugger, profiler, performance analysis |
| [Architecture](docs/architecture.md) | Custom types, separate components, folder structure, examples |

## Testing

```bash
# PHPStan + tests (same as CI)
composer check

# Run all tests / PHPStan separately
composer test
composer phpstan

# Without a local Redis server
vendor/bin/phpunit --exclude-group redis
```

## License

MIT
