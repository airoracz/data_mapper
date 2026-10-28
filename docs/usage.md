# Usage

[← Back to README](../README.md)

## Basic Usage

### 1. Mapping from JSON to Object

```php
use Pocta\DataMapper\Mapper;

$mapper = new Mapper();

// From JSON string
$json = '{"id": 1, "name": "John Doe", "active": true}';
$user = $mapper->fromJson($json, User::class);

// From array
$data = ['id' => 1, 'name' => 'John Doe', 'active' => true];
$user = $mapper->fromArray($data, User::class);
```

### 2. Mapping from Object to JSON/Array

```php
$user = new User(1, 'Jane Doe', true);

// To JSON string
$json = $mapper->toJson($user);

// To array
$array = $mapper->toArray($user);
```

### 3. Batch Processing (Collections)

Working with multiple objects at once is more efficient than processing them individually:

```php
use Pocta\DataMapper\Mapper;

$mapper = new Mapper();

// From array of arrays to array of objects
$data = [
    ['id' => 1, 'name' => 'John', 'active' => true],
    ['id' => 2, 'name' => 'Jane', 'active' => false],
    ['id' => 3, 'name' => 'Bob', 'active' => true],
];
$users = $mapper->fromArrayCollection($data, User::class);
// Returns: User[]

// From JSON array to array of objects
$json = '[
    {"id": 1, "name": "John", "active": true},
    {"id": 2, "name": "Jane", "active": false}
]';
$users = $mapper->fromJsonCollection($json, User::class);
// Returns: User[]

// From array of objects to array of arrays
$users = [new User(1, 'John', true), new User(2, 'Jane', false)];
$data = $mapper->toArrayCollection($users);
// Returns: [['id' => 1, 'name' => 'John', ...], ...]

// From array of objects to JSON array
$json = $mapper->toJsonCollection($users);
// Returns: '[{"id":1,"name":"John",...},{"id":2,"name":"Jane",...}]'
```

**Benefits of batch processing:**
- Cleaner code - no manual loops
- Consistent error handling across all items
- Better integration with profiler and debugger
- Type-safe with PHPStan generics

**Validation in collections:**
```php
// With strict mode, all items are validated
$mapper = new Mapper(MapperOptions::withStrictMode());

$data = [
    ['id' => 1, 'name' => 'John'],
    ['id' => 2, 'name' => 'Jane', 'unknown' => 'error'], // This will throw
];

try {
    $users = $mapper->fromArrayCollection($data, User::class);
} catch (ValidationException $e) {
    // Error during processing second item
    echo $e->getMessage();
}
```

### 4. CSV Import/Export

The mapper provides full CSV support with automatic type conversion, custom column mapping, and proper handling of special characters like quotes, commas, and newlines.

```php
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\Attributes\MapCsvColumn;

class Product
{
    public function __construct(
        public int $id,
        public string $name,
        public float $price,
        public bool $active
    ) {}
}

$mapper = new Mapper();

// CSV → Objects
$csv = "id,name,price,active\n1,Product A,10.50,true\n2,Product B,20.00,false";
$products = $mapper->fromCsv($csv, Product::class);
// Returns: Product[]

// Objects → CSV
$products = [
    new Product(1, 'Product A', 10.50, true),
    new Product(2, 'Product B', 20.00, false),
];
$csv = $mapper->toCsv($products);
// Returns: "id,name,price,active\n1,\"Product A\",10.5,true\n..."

// Read from CSV file
$products = $mapper->fromCsvFile('products.csv', Product::class);

// CSV without header row
$csv = "1,Product A,10.50\n2,Product B,20.00";
$products = $mapper->fromCsv($csv, Product::class, hasHeader: false);
// Maps by position: column 0 → id, column 1 → name, column 2 → price

// Custom delimiter (semicolon, tab, etc.)
$csv = "id;name;price\n1;Product A;10.50";
$products = $mapper->fromCsv($csv, Product::class, delimiter: ';');
```

**Custom column mapping with `MapCsvColumn` attribute:**

```php
class Product
{
    public function __construct(
        #[MapCsvColumn('product_id')]       // Map from column named "product_id"
        public int $id,

        #[MapCsvColumn('product_name')]     // Map from column named "product_name"
        public string $name,

        #[MapCsvColumn(index: 2)]           // Map from column at index 2 (0-based)
        public float $price
    ) {}
}

$csv = "product_id,product_name,price\n1,Widget,19.99\n2,Gadget,29.99";
$products = $mapper->fromCsv($csv, Product::class);
// Correctly maps despite different column names
```

**Special characters handling:**

The CSV parser properly handles quoted values, embedded commas, quotes, and newlines:

```php
$products = [
    new Product(1, 'Text with "quotes"', 9.99, true),
    new Product(2, 'Text with, comma', 14.99, true),
    new Product(3, "Text with\nnewline", 19.99, false),
];

$csv = $mapper->toCsv($products);
// Automatically escapes special characters:
// id,name,price,active
// 1,"Text with ""quotes""",9.99,true
// 2,"Text with, comma",14.99,true
// 3,"Text with
// newline",19.99,false

// Round-trip works perfectly
$parsedProducts = $mapper->fromCsv($csv, Product::class);
// Special characters are preserved correctly
```

**CSV options:**

```php
// All available options
$products = $mapper->fromCsv(
    csv: $csvString,
    className: Product::class,
    delimiter: ',',      // Field delimiter (default: ',')
    enclosure: '"',      // Field enclosure (default: '"')
    escape: '\\',        // Escape character (default: '\\')
    hasHeader: true      // Whether CSV has header row (default: true)
);

// Export with custom options
$csv = $mapper->toCsv(
    collection: $products,
    delimiter: ';',
    enclosure: '"',
    escape: '\\',
    includeHeader: true  // Include header row (default: true)
);
```

### 5. Partial Updates / Merge

Update only specific properties of an existing object without recreating it:

```php
use Pocta\DataMapper\Mapper;

$mapper = new Mapper();

// Existing object from database
$user = $userRepository->find(1); // User{id: 1, name: 'John', email: 'john@example.com', age: 30}

// Partial update from API request (PATCH endpoint)
$partialData = ['name' => 'Jane']; // Only update name
$mapper->merge($partialData, $user);

// Result: User{id: 1, name: 'Jane', email: 'john@example.com', age: 30}
// Only name was updated, other properties remain unchanged
```

**Skip null values:**
```php
// API sends null for fields that shouldn't be updated
$partialData = [
    'name' => 'Jane',
    'email' => null,  // Keep current email, don't update to null
    'age' => 35
];

$mapper->merge($partialData, $user, skipNull: true);

// Result: name and age updated, email unchanged
```

**With strict mode:**
```php
$mapper = new Mapper(MapperOptions::withStrictMode());

$partialData = [
    'name' => 'Jane',
    'unknown_field' => 'value' // This will throw ValidationException
];

try {
    $mapper->merge($partialData, $user);
} catch (ValidationException $e) {
    // Unknown key error
}
```

**Real-world PATCH endpoint example:**
```php
#[Route('/api/users/{id}', methods: ['PATCH'])]
public function updateUser(int $id, Request $request): Response
{
    $user = $this->userRepository->find($id);

    // Get partial data from request body
    $partialData = json_decode($request->getContent(), true);

    // Merge changes into existing entity
    $this->mapper->merge($partialData, $user, skipNull: true);

    // Validate and save
    $this->validator->validate($user);
    $this->em->flush();

    return $this->json($user);
}
```

**Benefits:**
- Perfect for PATCH endpoints
- Preserves unchanged properties
- Works with any object (not just newly created ones)
- Respects all type conversions and validations
- Optional strict mode for security
- Can skip null values to prevent accidental deletions

### 6. Object-to-DTO Mapping

Map data from existing objects (Doctrine Entities, other DTOs, POPOs) to target DTOs using property paths and getter methods. This is ideal for API responses, transforming database entities to DTOs, and data projections.

**Basic Object Mapping:**

```php
use Pocta\DataMapper\Mapper;

$mapper = new Mapper();

// Source: Doctrine Entity or any object
class UserEntity
{
    private string $firstName = 'John';
    private string $lastName = 'Doe';
    private bool $active = true;

    public function getFirstName(): string { return $this->firstName; }
    public function getLastName(): string { return $this->lastName; }
    public function isActive(): bool { return $this->active; }
}

// Target: Simple DTO
class UserDTO
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public bool $active
    ) {}
}

$entity = new UserEntity();
$dto = $mapper->fromObject($entity, UserDTO::class);
// Result: UserDTO with firstName='John', lastName='Doe', active=true
```

**Automatic Getter Resolution:**

The mapper automatically resolves property values using multiple strategies (in priority order):

1. **Getter methods**: `getPropertyName()`
2. **Direct method calls**: `propertyName()`
3. **Boolean methods**: `isPropertyName()`, `hasPropertyName()`
4. **Public properties**: Direct property access

```php
class Product
{
    private string $name = 'Product A';
    private int $stock = 100;
    public string $category = 'Electronics';

    public function getName(): string { return $this->name; }
    public function getStock(): int { return $this->stock; }
    public function hasStock(): bool { return $this->stock > 0; }
}

class ProductDTO
{
    public function __construct(
        public string $name,        // Resolved via getName()
        public int $stock,          // Resolved via getStock()
        public bool $inStock,       // Resolved via hasStock()
        public string $category     // Resolved via public property
    ) {}
}

$product = new Product();
$dto = $mapper->fromObject($product, ProductDTO::class);
```

**Nested Object Navigation with MapFrom:**

Use the `#[MapFrom]` attribute to specify property paths for accessing nested objects and collections:

```php
use Pocta\DataMapper\Attributes\MapFrom;

class Address
{
    public function __construct(
        private string $street,
        private string $city
    ) {}

    public function getStreet(): string { return $this->street; }
    public function getCity(): string { return $this->city; }
}

class UserEntity
{
    private Address $address;
    private array $addresses = [];

    public function __construct()
    {
        $this->address = new Address('Main St', 'New York');
        $this->addresses = [
            new Address('First Ave', 'Boston'),
            new Address('Second St', 'Chicago')
        ];
    }

    public function getAddress(): Address { return $this->address; }
    public function getAddresses(): array { return $this->addresses; }
}

class UserAddressDTO
{
    public function __construct(
        // Navigate to nested object property
        #[MapFrom('address.street')]
        public string $street,

        #[MapFrom('address.city')]
        public string $city,

        // Access array element by index
        #[MapFrom('addresses[0].street')]
        public string $firstAddressStreet,

        #[MapFrom('addresses[1].city')]
        public string $secondAddressCity
    ) {}
}

$entity = new UserEntity();
$dto = $mapper->fromObject($entity, UserAddressDTO::class);
// Result: street='Main St', city='New York', firstAddressStreet='First Ave', secondAddressCity='Chicago'
```

**Explicit Method Calls:**

Call specific methods on objects, including methods that don't follow getter conventions:

```php
class UserEntity
{
    private string $firstName = 'John';
    private string $lastName = 'Doe';
    private string $email = 'john@example.com';

    public function getFirstName(): string { return $this->firstName; }
    public function getLastName(): string { return $this->lastName; }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    // Method without 'get' prefix
    public function email(): string { return $this->email; }
}

class UserDTO
{
    public function __construct(
        // Call getFullName() method explicitly
        #[MapFrom('getFullName()')]
        public string $fullName,

        // Call email() method (no 'get' prefix)
        #[MapFrom('email()')]
        public string $emailAddress
    ) {}
}

$entity = new UserEntity();
$dto = $mapper->fromObject($entity, UserDTO::class);
// Result: fullName='John Doe', emailAddress='john@example.com'
```

**Nested Method Calls:**

Combine property paths with method calls for complex object graphs:

```php
class Address
{
    public function __construct(
        private string $street,
        private string $city
    ) {}

    public function getFullAddress(): string
    {
        return $this->street . ', ' . $this->city;
    }
}

class UserEntity
{
    public function __construct(
        private Address $address
    ) {}

    public function getAddress(): Address { return $this->address; }
}

class UserDTO
{
    public function __construct(
        // Navigate to address, then call getFullAddress()
        #[MapFrom('address.getFullAddress()')]
        public string $fullAddress
    ) {}
}

$entity = new UserEntity(new Address('Main St', 'Springfield'));
$dto = $mapper->fromObject($entity, UserDTO::class);
// Result: fullAddress='Main St, Springfield'
```

**Path Syntax:**

- **Dot notation**: `property.nestedProperty` - Navigate nested objects
- **Array indexes**: `property[0].nestedProperty` - Access array elements
- **Method calls**: `getMethod()` or `address.getMethod()` - Call methods
- **Mixed notation**: `user.addresses[0].getCity()` - Combine all features

**Real-World Example: Doctrine Entity to API Response:**

```php
// Doctrine Entity
class User
{
    private int $id;
    private string $firstName;
    private string $lastName;
    private Address $primaryAddress;
    /** @var Collection<Address> */
    private Collection $addresses;

    // Getters...
}

class Address
{
    private string $street;
    private string $city;
    private string $country;

    // Getters...
}

// API Response DTO
class UserResponseDTO
{
    public function __construct(
        public int $id,
        #[MapFrom('getFullName()')]
        public string $name,
        #[MapFrom('primaryAddress.street')]
        public string $street,
        #[MapFrom('primaryAddress.city')]
        public string $city,
        #[MapFrom('addresses[0].country')]
        public ?string $firstAddressCountry = null
    ) {}
}

// In your controller
$user = $entityManager->find(User::class, $id);
$response = $mapper->fromObject($user, UserResponseDTO::class);

return $this->json($response);
```

**Benefits:**

- Clean separation between domain entities and API DTOs
- No need for manual data transformation
- Type-safe with PHPStan support
- Works with all mapper features (filters, validators, events)
- Supports Doctrine Collections and complex object graphs
- Reduces boilerplate code in controllers and services

**Note:** Object mapping uses the same underlying flow as array mapping, so all features (filters, validation, events, etc.) work seamlessly.

### 7. Discriminator Mapping (Polymorphism)

The `DiscriminatorMap` attribute enables polymorphic object mapping based on a discriminator field. This is useful when deserializing data that can represent different concrete classes (e.g., different vehicle types, payment methods, or event types).

**Basic Example:**

```php
use Pocta\DataMapper\Attributes\DiscriminatorMap;

#[DiscriminatorMap(
    property: 'type',
    mapping: [
        'car' => Car::class,
        'bike' => Bike::class,
        'truck' => Truck::class,
    ]
)]
abstract class Vehicle
{
    protected string $type;

    protected string $brand;
    protected int $year;

    public function __construct(string $type, string $brand, int $year)
    {
        $this->type = $type;
        $this->brand = $brand;
        $this->year = $year;
    }
}

class Car extends Vehicle
{
    private int $doors;

    public function __construct(string $brand, int $year, int $doors = 4)
    {
        parent::__construct('car', $brand, $year);
        $this->doors = $doors;
    }
}

class Bike extends Vehicle
{
    private bool $electric;

    public function __construct(string $brand, int $year, bool $electric = false)
    {
        parent::__construct('bike', $brand, $year);
        $this->electric = $electric;
    }
}
```

**Usage:**

```php
$mapper = new Mapper();

// The mapper inspects 'type' field and instantiates the correct class
$carData = ['type' => 'car', 'brand' => 'Toyota', 'year' => 2020, 'doors' => 4];
$vehicle = $mapper->fromArray($carData, Vehicle::class);
// Result: Car instance with all properties set

$bikeData = ['type' => 'bike', 'brand' => 'Trek', 'year' => 2021, 'electric' => true];
$vehicle = $mapper->fromArray($bikeData, Vehicle::class);
// Result: Bike instance with all properties set
```

**With Collections:**

```php
$data = [
    ['type' => 'car', 'brand' => 'Toyota', 'year' => 2020, 'doors' => 4],
    ['type' => 'bike', 'brand' => 'Trek', 'year' => 2021, 'electric' => true],
    ['type' => 'truck', 'brand' => 'Ford', 'year' => 2019, 'capacity' => 5000]
];

$vehicles = $mapper->fromArrayCollection($data, Vehicle::class);
// Result: [Car, Bike, Truck] instances
```

**Custom Property Names:**

You can use `#[MapProperty]` on the discriminator field if your JSON uses a different name:

```php
use Pocta\DataMapper\Attributes\MapProperty;

#[DiscriminatorMap(
    property: 'event_type',
    mapping: [
        'user_created' => UserCreatedEvent::class,
        'order_placed' => OrderPlacedEvent::class,
    ]
)]
abstract class Event
{
    #[MapProperty(name: 'event_type')]
    protected string $eventType;

    protected string $timestamp;
}
```

**Error Handling:**

The mapper validates discriminator values and provides clear error messages:

```php
// Missing discriminator field
$data = ['brand' => 'Toyota', 'year' => 2020];
$mapper->fromArray($data, Vehicle::class);
// ValidationException: "Missing discriminator property 'type'"

// Unknown discriminator value
$data = ['type' => 'airplane', 'brand' => 'Boeing', 'year' => 2022];
$mapper->fromArray($data, Vehicle::class);
// ValidationException: "Unknown discriminator value 'airplane'. Available values: car, bike, truck"
```

**Benefits:**
- Clean polymorphic deserialization without manual type checking
- Type-safe: Each concrete class is properly typed
- Works with all mapper features (validation, filters, nested objects)
- Integrates seamlessly with collections (`fromArrayCollection`, `fromJsonCollection`)
- Clear error messages for debugging

**Use Cases:**
- API payloads with different entity types (vehicles, products, users)
- Event sourcing with multiple event types
- Payment processing with different payment methods
- Notification systems with various notification types
- Multi-tenant systems with different entity variants

## Class Definitions

### With MapProperty Attribute (recommended for custom names)

```php
use Pocta\DataMapper\Attributes\MapProperty;

class User
{
    #[MapProperty]
    private int $id;

    #[MapProperty]
    private string $name;

    #[MapProperty]
    private bool $active;

    // Custom name in JSON
    #[MapProperty(name: 'user_age')]
    private int $age;

    // Getters and setters...
}
```

### Without Attribute (automatic detection)

```php
class Product
{
    // All properties are automatically mapped based on their type
    private int $id;
    private string $title;
    private bool $enabled;
    private ?string $description;  // Nullable property

    // Getters and setters...
}
```

### With Constructor (Promoted Properties)

```php
use Pocta\DataMapper\Attributes\MapProperty;

class UserWithConstructor
{
    // Property outside constructor
    #[MapProperty]
    private string $email;

    public function __construct(
        #[MapProperty]
        private int $id,
        #[MapProperty]
        private string $name,
        #[MapProperty]
        private bool $active = true  // Default value
    ) {
    }

    // Getters and setters...
}
```
