# Advanced Features

[← Back to README](../README.md)

## Property Path Resolver (Nested Property Mapping)

The Property Path Resolver allows you to map nested values from complex data structures using dot notation and array indexes. This is useful when working with deeply nested JSON/API responses.

### Syntax Support

- **Dot notation**: `user.address.street` - access nested objects
- **Array indexes**: `addresses[0].street` - access array elements
- **Mixed notation**: `user.addresses[0].streetName` - combine both

### Basic Usage

```php
use Pocta\DataMapper\Attributes\MapProperty;

class UserDTO
{
    public function __construct(
        // Map from nested path user.permanentAddress.streetName
        #[MapProperty(path: 'user.permanentAddress.streetName')]
        public string $street,

        // Map from nested path user.permanentAddress.city
        #[MapProperty(path: 'user.permanentAddress.city')]
        public string $city
    ) {}
}

$data = [
    'user' => [
        'permanentAddress' => [
            'streetName' => 'Main Street',
            'city' => 'Prague'
        ]
    ]
];

$mapper = new Mapper();
$dto = $mapper->fromArray($data, UserDTO::class);
// $dto->street = 'Main Street'
// $dto->city = 'Prague'
```

### Array Index Access

```php
class CompanyDTO
{
    public function __construct(
        // Access first address
        #[MapProperty(path: 'user.addresses[0].streetName')]
        public string $firstAddress,

        // Access second address
        #[MapProperty(path: 'user.addresses[1].streetName')]
        public string $secondAddress
    ) {}
}

$data = [
    'user' => [
        'addresses' => [
            ['streetName' => 'First Street'],
            ['streetName' => 'Second Street']
        ]
    ]
];

$dto = $mapper->fromArray($data, CompanyDTO::class);
// $dto->firstAddress = 'First Street'
// $dto->secondAddress = 'Second Street'
```

### Complex Nested Structures

```php
class ManagerInfo
{
    public function __construct(
        #[MapProperty(path: 'company.departments[0].manager.name')]
        public string $managerName,

        #[MapProperty(path: 'company.departments[0].manager.email')]
        public string $managerEmail
    ) {}
}

$data = [
    'company' => [
        'departments' => [
            [
                'name' => 'IT',
                'manager' => [
                    'name' => 'John Doe',
                    'email' => 'john@example.com'
                ]
            ]
        ]
    ]
];

$manager = $mapper->fromArray($data, ManagerInfo::class);
// $manager->managerName = 'John Doe'
// $manager->managerEmail = 'john@example.com'
```

### Nullable Path Values

```php
class OptionalAddress
{
    public function __construct(
        #[MapProperty(path: 'user.address.street')]
        public ?string $street = null
    ) {}
}

// Missing nested data returns null
$data = ['user' => ['name' => 'John']];
$dto = $mapper->fromArray($data, OptionalAddress::class);
// $dto->street = null (no error thrown)
```

### Usage with MapDateTimeProperty

The `path` parameter also works with `MapDateTimeProperty`:

```php
use Pocta\DataMapper\Attributes\MapDateTimeProperty;

class EventDTO
{
    public function __construct(
        #[MapDateTimeProperty(
            path: 'event.metadata.createdAt',
            format: 'Y-m-d H:i:s',
            timezone: 'Europe/Prague'
        )]
        public DateTimeImmutable $createdAt
    ) {}
}

$data = [
    'event' => [
        'metadata' => [
            'createdAt' => '2024-01-15 10:30:00'
        ]
    ]
];

$event = $mapper->fromArray($data, EventDTO::class);
// $event->createdAt is DateTimeImmutable object
```

### DateTime Output Format (`outputFormat`)

By default, DateTime properties are serialized to ISO 8601 with microseconds (`Y-m-d\TH:i:s.uP`) when converting objects to JSON/array. Use the `outputFormat` parameter to customize the output:

```php
use Pocta\DataMapper\Attributes\MapDateTimeProperty;

class MeetingRecord
{
    public int $id;

    // Output as date only (Y-m-d)
    #[MapDateTimeProperty(outputFormat: 'Y-m-d')]
    public DateTimeImmutable $createdAt;
}

$record = new MeetingRecord();
$record->id = 1;
$record->createdAt = new DateTimeImmutable('2025-03-21 12:15:35');

$data = $mapper->toArray($record);
// ['id' => 1, 'createdAt' => '2025-03-21']

$json = $mapper->toJson($record);
// {"id":1,"createdAt":"2025-03-21"}
```

**Common output formats:**

```php
class Example
{
    // Date only
    #[MapDateTimeProperty(outputFormat: 'Y-m-d')]
    public DateTimeImmutable $date;
    // Output: "2025-03-21"

    // DateTime without timezone
    #[MapDateTimeProperty(outputFormat: 'Y-m-d H:i:s')]
    public DateTimeImmutable $datetime;
    // Output: "2025-03-21 12:15:35"

    // ATOM format (RFC 3339)
    #[MapDateTimeProperty(outputFormat: DateTimeInterface::ATOM)]
    public DateTimeImmutable $atom;
    // Output: "2025-03-21T12:15:35+00:00"

    // Time only
    #[MapDateTimeProperty(outputFormat: 'H:i:s')]
    public DateTimeImmutable $time;
    // Output: "12:15:35"

    // Custom format
    #[MapDateTimeProperty(outputFormat: 'd.m.Y')]
    public DateTimeImmutable $czechDate;
    // Output: "21.03.2025"
}
```

**Input and output formats can differ:**

```php
class Event
{
    // Parse input as "d/m/Y H:i", serialize output as "Y-m-d"
    #[MapDateTimeProperty(format: 'd/m/Y H:i', outputFormat: 'Y-m-d')]
    public DateTimeImmutable $eventDate;
}

$event = $mapper->fromArray(['eventDate' => '21/03/2025 12:15'], Event::class);
$data = $mapper->toArray($event);
// ['eventDate' => '2025-03-21']
```

**Parameters overview:**

| Parameter | Direction | Description |
|-----------|-----------|-------------|
| `format` | Input (denormalization) | Custom format for parsing input strings to DateTime |
| `outputFormat` | Output (normalization) | Custom format for serializing DateTime to string |
| `timezone` | Input | Timezone for parsing input |

> **Note:** Without `outputFormat`, DateTime properties always serialize to ISO 8601 with microseconds: `2025-03-21T12:15:35.000000+00:00`. Without `MapDateTimeProperty` attribute at all, the same default applies — the mapper automatically detects `DateTimeImmutable`/`DateTime` types from PHP type hints.

### Important Notes

- **Mutually exclusive**: Cannot use both `name` and `path` parameters together
- **Nullable handling**: If path doesn't exist and property is nullable, returns `null`
- **Non-nullable**: If path doesn't exist and property is required, throws `ValidationException`
- **Strict mode**: The root key of a `path` (e.g. `address` for `address.city`) is accepted as known; keys nested under it are not validated
- **Type safety**: All type conversions and filters work normally with path-resolved values
- **Normalization**: By default, a property with `path` is written to output as a flat key (the property name),
  so `toArray()` output cannot be fed back to `fromArray()`. Enable `symmetricPropertyPaths` to write it back
  into the same nested structure:

```php
$mapper = new Mapper(new MapperOptions(symmetricPropertyPaths: true));

class AddressDto
{
    #[MapProperty(path: 'address.city')]
    public string $city;
}

$mapper->toArray($dto);   // ['address' => ['city' => 'Prague']]   (default: ['city' => 'Prague'])
```

  In 1.x the option defaults to `false` for backward compatibility.

### Error Handling with Detailed Context

When path resolution fails, the mapper provides detailed error messages that help you quickly identify the problem:

```php
class UserDTO
{
    public function __construct(
        #[MapProperty(path: 'user.profile.email')]
        public string $email
    ) {}
}

$data = [
    'user' => [
        'profile' => [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'age' => 30
            // 'email' is missing!
        ]
    ]
];

try {
    $mapper->fromArray($data, UserDTO::class);
} catch (ValidationException $e) {
    $errors = $e->getErrors();
    // Result:
    // [
    //     'user.profile.email' => "Missing required property 'email' at path 'user.profile.email'
    //                              (path resolution failed at 'user.profile.email',
    //                              available keys: [firstName, lastName, age])"
    // ]
}
```

**Error message features:**
- **Full path**: Shows exactly where the data is missing
- **Failed location**: Indicates at which point in the path resolution failed
- **Available keys**: Lists what keys are actually present at that location
- **Array bounds**: For array access, shows how many elements exist

```php
// Array index out of bounds
#[MapProperty(path: 'addresses[5].street')]
public string $street;

// Data has only 2 addresses
$data = ['addresses' => [['street' => 'A'], ['street' => 'B']]];

// Error: "Missing required property 'street' at path 'addresses[5].street'
//         (path resolution failed at 'addresses.5', array has 2 elements)"
```

**Invalid path syntax:**
```php
#[MapProperty(path: 'addresses[abc].street')] // Invalid: non-numeric index
public string $street;

// Error: "Invalid property path syntax for parameter 'street':
//         array index must be numeric in 'addresses[abc].street'"
```

### Use Cases

- Mapping from external APIs with nested responses
- Extracting specific fields from complex JSON structures
- Simplifying DTOs by flattening nested data
- Working with GraphQL responses
- Accessing array elements at specific positions

## Filters (post-processing)

Filters are attributes that modify values after mapping. They are applied:
- during normalization (object → array/JSON) after type conversion,
- during denormalization (array/JSON → object) before type conversion.

Usage on properties (attribute order is application order):

```php
use Pocta\DataMapper\Attributes\Filters\{StringTrimFilter,StringToLowerFilter,StripTagsFilter,ToNullFilter};

class Article
{
    #[StringTrimFilter]
    #[StringToLowerFilter]
    public string $slug;

    #[StripTagsFilter('<b><i>')]
    public string $excerpt;

    #[ToNullFilter(values: ['','N/A'])]
    public ?string $subtitle = null;
}
```

Available filters (overview):

- **Strings**: `StringTrimFilter`, `StringToLowerFilter`, `StringToUpperFilter`, `CollapseWhitespaceFilter`, `TitleCaseFilter`, `CapitalizeFirstFilter`, `EnsurePrefixFilter`, `EnsureSuffixFilter`, `SubstringFilter`, `TrimLengthFilter`, `PadLeftFilter`, `PadRightFilter`, `ReplaceDiacriticsFilter`, `SlugifyFilter`, `NormalizeUnicodeFilter`, `ReplaceFilter`, `TransliterateFilter`.
- **Case Conversion**: `CamelCaseFilter`, `SnakeCaseFilter`, `KebabCaseFilter`.
- **Numbers**: `ClampFilter`, `RoundNumberFilter`, `CeilNumberFilter`, `FloorNumberFilter`, `AbsNumberFilter`, `ScaleNumberFilter`, `ToDecimalStringFilter`, `MoneyFilter`, `NumberFormatFilter`, `PriceRoundFilter`.
- **Boolean**: `ToBoolStrictFilter`, `NullIfTrueFilter`, `NullIfFalseFilter`.
- **Arrays/Collections**: `EachFilter`, `UniqueArrayFilter`, `SortArrayFilter`, `SortArrayByKeyFilter`, `ReverseArrayFilter`, `FilterKeysFilter`, `SliceArrayFilter`, `LimitArrayFilter`, `FlattenArrayFilter`, `ArrayCastFilter`.
- **Date/Time**: `ToTimezoneFilter`, `StartOfDayFilter`, `EndOfDayFilter`, `TruncateDateTimeFilter`, `AddIntervalFilter`, `SubIntervalFilter`, `ToUnixTimestampFilter`, `EnsureImmutableFilter`.
- **Formatting**: `JsonDecodeFilter`, `JsonEncodeFilter`, `UrlEncodeFilter`, `UrlDecodeFilter`, `HtmlEntitiesEncodeFilter`, `HtmlEntitiesDecodeFilter`, `SanitizeHtmlFilter`, `Base64EncodeFilter`, `Base64DecodeFilter`.
- **Security**: `HashFilter`, `MaskFilter`.
- **Data Normalization**: `NormalizeEmailFilter`, `NormalizePhoneFilter`, `DefaultValueFilter`, `CoalesceFilter`.
- **Generation**: `GenerateUuidFilter`.

Example of combining multiple filters:

```php
use Pocta\DataMapper\Attributes\Filters\{SlugifyFilter,TrimLengthFilter,EnsurePrefixFilter};

class Article
{
    // URL-friendly slug with prefix and trimming
    #[SlugifyFilter('-')]
    #[TrimLengthFilter(80, '…')]
    #[EnsurePrefixFilter('art-')]
    public string $slug;
}
```

Filters over arrays (process each item through filter):

```php
use Pocta\DataMapper\Attributes\Filters\{EachFilter,StringTrimFilter,UniqueArrayFilter,SortArrayFilter};

class Tags
{
    #[EachFilter(StringTrimFilter::class)]
    #[UniqueArrayFilter]
    #[SortArrayFilter]
    public array $tags = [];
}
```

Note: Filters are applied in declaration order. Each filter is null-safe and type-conservative (leaves unsupported types unchanged).

### Specialized Filters

#### Data Normalization Filters

**NormalizeEmailFilter** - Normalizes email addresses to lowercase and trims whitespace:

```php
use Pocta\DataMapper\Attributes\Filters\NormalizeEmailFilter;

class User
{
    #[NormalizeEmailFilter]
    public string $email;
    // Input: "  John.Doe@EXAMPLE.COM  "
    // Output: "john.doe@example.com"
}
```

**NormalizePhoneFilter** - Removes all non-digit characters from phone numbers:

```php
use Pocta\DataMapper\Attributes\Filters\NormalizePhoneFilter;

class Contact
{
    #[NormalizePhoneFilter]
    public string $phone;
    // Input: "+1 (555) 123-4567"
    // Output: "15551234567"

    #[NormalizePhoneFilter(keepPlus: true)]
    public string $internationalPhone;
    // Input: "+420 123 456 789"
    // Output: "+420123456789"
}
```

**DefaultValueFilter** - Provides a default value if input is null or empty:

```php
use Pocta\DataMapper\Attributes\Filters\DefaultValueFilter;

class Article
{
    #[DefaultValueFilter('Draft')]
    public ?string $status;
    // Input: null → Output: "Draft"

    #[DefaultValueFilter('Untitled', replaceEmpty: true)]
    public string $title;
    // Input: "" → Output: "Untitled"
}
```

**CoalesceFilter** - Returns the first non-null value from provided fallbacks:

```php
use Pocta\DataMapper\Attributes\Filters\CoalesceFilter;

class Settings
{
    #[CoalesceFilter('default', 'fallback')]
    public ?string $theme;
    // Input: null → Output: "default"
    // Input: "custom" → Output: "custom"
}
```

**SanitizeHtmlFilter** - Strips HTML tags or allows only specific tags:

```php
use Pocta\DataMapper\Attributes\Filters\SanitizeHtmlFilter;

class Post
{
    #[SanitizeHtmlFilter]
    public string $plainText;
    // Input: "<script>alert('XSS')</script>Hello <b>World</b>"
    // Output: "alert('XSS')Hello World"

    #[SanitizeHtmlFilter('<b><i><u>')]
    public string $richText;
    // Input: "<p>Hello <b>World</b> <script>evil</script></p>"
    // Output: "Hello <b>World</b> evil"
}
```

**ReplaceFilter** - Replaces occurrences of search string with replacement (supports regex):

```php
use Pocta\DataMapper\Attributes\Filters\ReplaceFilter;

class Product
{
    #[ReplaceFilter('_', '-')]
    public string $slug;
    // Input: "hello_world_test"
    // Output: "hello-world-test"

    #[ReplaceFilter('/[^a-z0-9-]/', '', useRegex: true)]
    public string $urlSafe;
    // Input: "Hello World! 123"
    // Output: "elloorld-123"

    #[ReplaceFilter('old', 'new', caseInsensitive: true)]
    public string $text;
    // Input: "OLD value OLD"
    // Output: "new value new"
}
```

#### Advanced Data Transformation Filters

**MoneyFilter** - Formats numeric values as money strings with configurable separators:

```php
use Pocta\DataMapper\Attributes\Filters\MoneyFilter;

class Product
{
    #[MoneyFilter(decimals: 2, decimalSeparator: ',', thousandsSeparator: ' ')]
    public float $price;
    // Input: 1234.56
    // Output: "1 234,56"

    #[MoneyFilter(decimals: 0, thousandsSeparator: ',')]
    public int $total;
    // Input: 1234567
    // Output: "1,234,567"
}
```

**NumberFormatFilter** - Flexible number formatting with prefix/suffix support:

```php
use Pocta\DataMapper\Attributes\Filters\NumberFormatFilter;

class Metrics
{
    #[NumberFormatFilter(decimals: 2, prefix: '$', suffix: ' USD')]
    public float $amount;
    // Input: 1234.56
    // Output: "$1234.56 USD"

    #[NumberFormatFilter(decimals: 0, thousandsSep: ',')]
    public int $visitors;
    // Input: 1234567
    // Output: "1,234,567"
}
```

**MaskFilter** - Masks sensitive data for GDPR compliance and security:

```php
use Pocta\DataMapper\Attributes\Filters\MaskFilter;

class Payment
{
    #[MaskFilter(mask: '****', visibleStart: 4, visibleEnd: 4)]
    public string $cardNumber;
    // Input: "1234567890123456"
    // Output: "1234****3456"

    #[MaskFilter(mask: '***', visibleStart: 3, visibleEnd: 0)]
    public string $email;
    // Input: "test@example.com"
    // Output: "tes***"

    #[MaskFilter(visibleStart: 2, visibleEnd: 2, maskChar: '*')]
    public string $iban;
    // Input: "CZ6508000000192000145399"
    // Output: "CZ******************99"
}
```

**HashFilter** - Hashes values using various algorithms (bcrypt, argon2, md5, sha256, etc.):

```php
use Pocta\DataMapper\Attributes\Filters\HashFilter;

class User
{
    #[HashFilter(algo: 'bcrypt')]
    public string $password;
    // Input: "secret123"
    // Output: "$2y$10$..."

    #[HashFilter(algo: 'sha256')]
    public string $apiToken;
    // Input: "token123"
    // Output: "3a6eb0794f..."

    #[HashFilter(algo: 'md5')]
    public string $checksum;
    // Input: "data"
    // Output: "8d777f385d..."
}
```

**TransliterateFilter** - Transliterates text from one script to another (e.g., Cyrillic to Latin):

```php
use Pocta\DataMapper\Attributes\Filters\TransliterateFilter;

class Article
{
    #[TransliterateFilter]
    public string $slug;
    // Input: "Привет мир"
    // Output: "Privet mir"

    #[TransliterateFilter(removeUnknown: true)]
    public string $cleanSlug;
    // Removes characters that cannot be transliterated
}
```

**CamelCaseFilter / SnakeCaseFilter / KebabCaseFilter** - Case conversion filters:

```php
use Pocta\DataMapper\Attributes\Filters\{CamelCaseFilter,SnakeCaseFilter,KebabCaseFilter};

class ApiMapping
{
    #[CamelCaseFilter]
    public string $propertyName;
    // Input: "hello_world"
    // Output: "helloWorld"

    #[CamelCaseFilter(upperFirst: true)]
    public string $className;
    // Input: "hello_world"
    // Output: "HelloWorld" (PascalCase)

    #[SnakeCaseFilter]
    public string $databaseColumn;
    // Input: "helloWorld"
    // Output: "hello_world"

    #[SnakeCaseFilter(screaming: true)]
    public string $constant;
    // Input: "helloWorld"
    // Output: "HELLO_WORLD"

    #[KebabCaseFilter]
    public string $urlSlug;
    // Input: "helloWorld"
    // Output: "hello-world"

    #[KebabCaseFilter(screaming: true)]
    public string $httpHeader;
    // Input: "contentType"
    // Output: "CONTENT-TYPE"
}
```

**Base64EncodeFilter / Base64DecodeFilter** - Base64 encoding/decoding:

```php
use Pocta\DataMapper\Attributes\Filters\{Base64EncodeFilter,Base64DecodeFilter};

class ApiData
{
    #[Base64EncodeFilter]
    public string $encodedData;
    // Input: "hello"
    // Output: "aGVsbG8="

    #[Base64EncodeFilter(urlSafe: true)]
    public string $urlSafeToken;
    // Uses URL-safe Base64 encoding (replaces +/ with -_)

    #[Base64EncodeFilter(removePadding: true)]
    public string $compactToken;
    // Input: "hello"
    // Output: "aGVsbG8" (without padding)

    #[Base64DecodeFilter]
    public string $decodedData;
    // Note: Best used for specific decoding workflows
}
```

**PriceRoundFilter** - Rounds prices to psychological pricing points:

```php
use Pocta\DataMapper\Attributes\Filters\PriceRoundFilter;

class Product
{
    #[PriceRoundFilter(to: 9)]
    public float $price;
    // Input: 123.45
    // Output: 129.00

    #[PriceRoundFilter(to: 99)]
    public float $retailPrice;
    // Input: 123.45
    // Output: 199.00

    #[PriceRoundFilter(to: 95)]
    public float $salePrice;
    // Input: 123.45
    // Output: 195.00

    #[PriceRoundFilter(to: 0)]
    public float $wholesalePrice;
    // Input: 123.45
    // Output: 130.00 (rounds to nearest 10)

    #[PriceRoundFilter(to: 99, subtract: true)]
    public float $clearancePrice;
    // Input: 123.45
    // Output: 99.00 (rounds down)
}
```

**GenerateUuidFilter** - Generates RFC 4122 compliant UUIDs for null/empty values:

```php
use Pocta\DataMapper\Attributes\Filters\GenerateUuidFilter;

class Entity
{
    #[GenerateUuidFilter]
    public ?string $id;
    // Input: null
    // Output: "550e8400-e29b-41d4-a716-446655440000"

    #[GenerateUuidFilter(version: 4)]
    public string $uuid;
    // Input: ""
    // Output: "6ba7b810-9dad-11d1-80b4-00c04fd430c8"

    #[GenerateUuidFilter(onlyIfNull: true)]
    public ?string $identifier;
    // Only generates if null, leaves empty strings unchanged
    // Input: null → "550e8400-..."
    // Input: "" → ""
}
```

## Value Hydration (MapPropertyWithFunction)

Using the `MapPropertyWithFunction` attribute, you can "hydrate" a value with a custom function. The function is called with a single parameter (payload) and its return value is then type-processed by the mapper. Hydration occurs even if the key for the property is missing in the source JSON.

Payload modes (`HydrationMode`):

- `VALUE` – passes the current property value to the function
- `PARENT` – passes the parent payload (array for current object)
- `FULL` – passes the root payload (top-level input array)

Usage with string callable:

```php
use Pocta\DataMapper\Attributes\MapPropertyWithFunction;
use Pocta\DataMapper\Attributes\HydrationMode;

class User
{
    // Passes current email value to strtoupper
    #[MapPropertyWithFunction(function: 'strtoupper', mode: HydrationMode::VALUE)]
    public string $email;
}
```

Static method (callable-string):

```php
class Transformer
{
    public static function makeUsername(mixed $payload): string
    {
        // $payload is parent payload (e.g. ['first' => 'John', 'last' => 'Doe'])
        return strtolower(($payload['first'] ?? '') . '.' . ($payload['last'] ?? ''));
    }
}

class User
{
    public string $first;
    public string $last;

    #[MapPropertyWithFunction(function: Transformer::class . '::makeUsername', mode: HydrationMode::PARENT)]
    public string $username;
}
```

Array callable (e.g. `[self::class, 'method']`):

```php
class User
{
    #[MapPropertyWithFunction(function: [self::class, 'normalizeEmail'], mode: HydrationMode::VALUE)]
    public string $email;

    public static function normalizeEmail(mixed $value): string
    {
        return is_string($value) ? strtolower(trim($value)) : '';
    }
}
```

Hydration from root payload (`FULL`):

```php
class Profile
{
    public string $name;

    // Extracts e.g. meta.source from root payload
    #[MapPropertyWithFunction(function: [self::class, 'extractSource'], mode: HydrationMode::FULL)]
    public string $source;

    public static function extractSource(mixed $payload): string
    {
        return is_array($payload) ? (string)($payload['meta']['source'] ?? '') : '';
    }
}
```

Note: `MapPropertyWithFunction` is called before type conversion in denormalization (pre-denormalize). If a post-denormalize phase is needed (e.g. on already created `DateTimeInterface`), it can be added as an extension.

## Nullable Properties

```php
class Product
{
    #[MapProperty]
    private int $id;  // Required

    #[MapProperty]
    private ?string $description;  // Optional

    #[MapProperty]
    private ?int $stock;  // Optional
}

// JSON with null values
$json = '{"id": 1, "description": null, "stock": 100}';
$product = $mapper->fromJson($json, Product::class);

$product->getDescription();  // null
$product->getStock();        // 100
```

## Custom Property Names

```php
class Order
{
    #[MapProperty(name: 'order_id')]
    private int $id;

    #[MapProperty(name: 'customer_name')]
    private string $customerName;
}

// JSON uses different keys
$json = '{"order_id": 123, "customer_name": "Alice"}';
$order = $mapper->fromJson($json, Order::class);
```

## Explicit Type

```php
class Config
{
    // If JSON contains value as string but we want int
    #[MapProperty(type: 'int')]
    private int $port;

    // If JSON contains "1"/"0" as strings
    #[MapProperty(type: 'bool')]
    private bool $enabled;
}
```

## Automatic Type Conversion

The mapper can automatically convert values:

### Integer
- `"42"` → `42`
- `42.7` → `42`

### Boolean
- `"true"`, `"1"`, `1` → `true`
- `"false"`, `"0"`, `0`, `""` → `false`

### String
- Any scalar value → string

### Null Values
- `null` → `null` (only for nullable properties)
- Missing values → `null` or default value from constructor
