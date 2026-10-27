# Architecture

[← Back to README](../README.md)

The library is built on clean architecture with separation of concerns.

## Data Types (Types)

Each supported data type has its own class that handles value conversion:

```php
use Pocta\DataMapper\Types\IntType;
use Pocta\DataMapper\Types\StringType;
use Pocta\DataMapper\Types\BoolType;
use Pocta\DataMapper\Types\TypeResolver;

// Register custom type
$typeResolver = new TypeResolver();

// Use type directly
$intType = new IntType();
$value = $intType->denormalize("42", "fieldName", false); // 42 (int)
$normalized = $intType->normalize(42); // 42 (int)
```

### Implementing Custom Type

```php
use Pocta\DataMapper\Types\AbstractType;

class FloatType extends AbstractType
{
    public function getName(): string
    {
        return 'float';
    }

    public function getAliases(): array
    {
        return ['float', 'double'];
    }

    protected function denormalizeValue(mixed $value, string $fieldName): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        throw new \InvalidArgumentException(
            "Cannot cast value of field '{$fieldName}' to float"
        );
    }

    protected function normalizeValue(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}

// Register custom type
$typeResolver = new TypeResolver();
$typeResolver->registerType(new FloatType());

$denormalizer = new Denormalizer($typeResolver);
$normalizer = new Normalizer($typeResolver);
$mapper = new Mapper($denormalizer, $normalizer);
```

## Separate Components

```php
use Pocta\DataMapper\Normalizer\Normalizer;
use Pocta\DataMapper\Denormalizer\Denormalizer;
use Pocta\DataMapper\Types\TypeResolver;

// TypeResolver: Type management
$typeResolver = new TypeResolver();

// Normalizer: Object → Array/JSON
$normalizer = new Normalizer($typeResolver);
$array = $normalizer->normalize($object);

// Denormalizer: Array/JSON → Object
$denormalizer = new Denormalizer($typeResolver);
$object = $denormalizer->denormalize($array, User::class);

// Mapper: Facade combining both components
$mapper = new Mapper($denormalizer, $normalizer);
```

## Folder Structure

```
src/
├── Attributes/
│   ├── MapProperty.php          # Attribute for property mapping
│   ├── Filters/                 # 60+ filter attributes
│   └── ...
├── Cache/
│   ├── CacheInterface.php       # Cache interface
│   ├── ArrayCache.php           # In-memory cache
│   ├── NullCache.php            # No-op cache
│   └── ClassMetadataFactory.php # Metadata factory
├── Debug/
│   ├── Debugger.php             # Debug logger
│   ├── Profiler.php             # Performance profiler
│   └── ProfileReport.php        # Formatted reports
├── Denormalizer/
│   └── Denormalizer.php         # Data to object conversion
├── Events/
│   ├── EventInterface.php       # Event interface
│   ├── EventDispatcher.php      # Event dispatcher
│   └── *Event.php               # Event classes
├── Exceptions/
│   └── ValidationException.php  # Validation exception
├── Normalizer/
│   └── Normalizer.php           # Object to data conversion
├── Types/
│   ├── TypeInterface.php        # Type interface
│   ├── AbstractType.php         # Abstract base class
│   ├── IntType.php              # Integer type
│   ├── StringType.php           # String type
│   ├── BoolType.php             # Boolean type
│   └── TypeResolver.php         # Type manager
├── Validation/
│   ├── AssertInterface.php      # Validator interface
│   ├── Validator.php            # Validator
│   └── *Assert.php              # 30+ validator attributes
└── Mapper.php                   # Main facade
```

## Error Handling

```php
use JsonException;
use Pocta\DataMapper\Exceptions\InvalidInputException;
use Pocta\DataMapper\Exceptions\ValidationException;

try {
    $user = $mapper->fromJson($json, User::class);
} catch (JsonException $e) {
    // Malformed JSON, or nesting deeper than MapperOptions::$jsonDepth      → client error (400)
} catch (InvalidInputException $e) {
    // Wrong input shape: JSON scalar/null/list where an object is expected → client error (400)
} catch (ValidationException $e) {
    // Invalid value, missing required field, unknown key in strict mode    → client error (422)
    $errors = $e->getErrors();
}
```

Both `InvalidInputException` and `ValidationException` extend `InvalidArgumentException`, so existing
`catch (InvalidArgumentException)` blocks keep working. Anything else (e.g. `LogicException` for a
misconfigured DTO, `TypeError`) is a programming error.

Input shape rules:

- `fromJson()` requires a JSON object at the top level - `"str"`, `1`, `null`, `[1, 2]` and even `[]` are rejected.
- `fromArray()` rejects a non-empty list (`[1, 2]`); an empty array is accepted as an empty object.
- `fromJsonCollection()` requires a JSON array, and `fromArrayCollection()` requires every element to be an associative array.

## Examples

### Round-trip Conversion

```php
$originalJson = '{"id": 1, "name": "Test", "active": true}';

// JSON → Object
$user = $mapper->fromJson($originalJson, User::class);

// Object → JSON
$newJson = $mapper->toJson($user);

// Result is equivalent to original JSON
```

### Working with Collections

```php
$usersData = [
    ['id' => 1, 'name' => 'Alice'],
    ['id' => 2, 'name' => 'Bob'],
    ['id' => 3, 'name' => 'Charlie']
];

$users = array_map(
    fn($data) => $mapper->fromArray($data, User::class),
    $usersData
);
```

### Arrays of Scalars

The `arrayOf` attribute supports scalar types (int, string, float, bool) and objects. Use `ArrayElementType` enum (recommended) or string type names for backward compatibility.

**Supported types in `arrayOf`:**
- Scalar types: `ArrayElementType::Int`, `ArrayElementType::String`, `ArrayElementType::Float`, `ArrayElementType::Bool` (or string equivalents: `'int'`, `'string'`, `'float'`, `'bool'`)
- Objects: Any class name (e.g., `Address::class`, `User::class`)
- Complex types: `DateTime`, `DateTimeImmutable`, `Array` - these are NOT supported in `arrayOf` and will throw an `InvalidArgumentException`

**For arrays of DateTime objects**, use the class name directly:
```php
// For arrays of DateTime objects, use class-string
#[MapProperty(type: PropertyType::Array, arrayOf: \DateTime::class)]
public array $dates;
```

**Example with scalar arrays:**

```php
use Pocta\DataMapper\Attributes\ArrayElementType;
use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Attributes\PropertyType;

class Product
{
    public function __construct(
        public int $id,
        public string $name,

        // Array of integers - using ArrayElementType enum (recommended)
        #[MapProperty(type: PropertyType::Array, arrayOf: ArrayElementType::Int)]
        public array $scores,

        // Array of strings - using ArrayElementType enum (recommended)
        #[MapProperty(type: PropertyType::Array, arrayOf: ArrayElementType::String)]
        public array $tags,

        // Array of floats - using ArrayElementType enum (recommended)
        #[MapProperty(type: PropertyType::Array, arrayOf: ArrayElementType::Float)]
        public array $prices,

        // Array of booleans - using ArrayElementType enum (recommended)
        #[MapProperty(type: PropertyType::Array, arrayOf: ArrayElementType::Bool)]
        public array $flags,

        // Backward compatibility - string type names still work
        #[MapProperty(type: PropertyType::Array, arrayOf: 'int')]
        public array $ratings = []
    ) {}
}

$data = [
    'id' => 1,
    'name' => 'Example Product',
    'scores' => [10, 20, 30, 40],
    'tags' => ['php', 'testing', 'mapper'],
    'prices' => [10.5, 20.99, 30.0],
    'flags' => [true, false, true],
    'ratings' => [5, 4, 3]
];

$product = $mapper->fromArray($data, Product::class);
// $product->scores is array<int>: [10, 20, 30, 40]
// $product->tags is array<string>: ['php', 'testing', 'mapper']
// $product->prices is array<float>: [10.5, 20.99, 30.0]
// $product->flags is array<bool>: [true, false, true]
// $product->ratings is array<int>: [5, 4, 3]

// Type conversion is automatic
$dataWithStrings = [
    'id' => 1,
    'name' => 'Example Product',
    'scores' => ['10', '20', '30'], // strings will be converted to int
    'tags' => ['php', 'testing'],
    'prices' => [],
    'flags' => [],
    'ratings' => ['5', '4'] // strings will be converted to int
];

$product = $mapper->fromArray($dataWithStrings, Product::class);
// $product->scores is array<int>: [10, 20, 30] - converted from strings
// $product->ratings is array<int>: [5, 4] - converted from strings
```

## Performance and Design

- **Minimal overhead**: Reflection used only where necessary
- **Type-safe**: Strict typing ensures data validity
- **Lazy initialization**: Properties initialized only when needed
- **SOLID principles**: Clean architecture with separation of concerns
- **Strategy pattern**: Data types as conversion strategies
- **Dependency Injection**: TypeResolver is injectable for testing
