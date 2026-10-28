# Validation System

[← Back to README](../README.md)

The Validation System provides declarative validation using Assert attributes directly on properties.

## Auto-validation

Automatic validation after denormalization:

```php
use Pocta\DataMapper\Validation\NotNull;
use Pocta\DataMapper\Validation\Range;
use Pocta\DataMapper\Validation\Email;

class User
{
    #[NotNull]
    #[Range(min: 1)]
    public int $id;

    #[NotNull]
    #[Email]
    public string $email;

    #[Range(min: 18, max: 120)]
    public int $age;
}

// Auto-validation enabled
$mapper = new Mapper(autoValidate: true);

try {
    $user = $mapper->fromArray(['id' => 0, 'email' => 'invalid'], User::class);
} catch (ValidationException $e) {
    $errors = $e->getErrors();
    // ['id' => "must be at least 1", 'email' => "must be valid email"]
}
```

## Strict Mode

Strict mode validates that input data contains only known keys defined in the target class. When enabled, any unknown keys will cause a `ValidationException`.

```php
// Enable strict mode in constructor
$mapper = new Mapper(strictMode: true);

class User
{
    public function __construct(
        public int $id,
        public string $name
    ) {}
}

// This will throw ValidationException because 'unknown_field' is not defined in User
try {
    $data = ['id' => 1, 'name' => 'John', 'unknown_field' => 'value'];
    $user = $mapper->fromArray($data, User::class);
} catch (ValidationException $e) {
    $errors = $e->getErrors();
    // ['unknown_field' => "Unknown key 'unknown_field' at path 'unknown_field' is not allowed in strict mode"]
}

// Works fine - all keys are known
$data = ['id' => 1, 'name' => 'John'];
$user = $mapper->fromArray($data, User::class); // OK

// Dynamic control
$mapper->setStrictMode(false); // Disable
$mapper->setStrictMode(true);  // Enable

if ($mapper->isStrictMode()) {
    // Strict mode is enabled
}
```

**Benefits:**
- Catches typos in input data keys
- Prevents accidental data leakage
- Ensures API contracts are strictly followed
- Useful for debugging and development

**Note:** By default, strict mode is disabled (`false`) to maintain backward compatibility.

## Manual Validation

```php
$mapper = new Mapper(); // autoValidate = false

$user = $mapper->fromArray($data, User::class);

// Validation without exception
$errors = $mapper->validate($user, throw: false);
if (!empty($errors)) {
    // Handle errors
}

// Validation with exception
try {
    $mapper->validate($user);
} catch (ValidationException $e) {
    // Handle validation errors
}
```

## Error Messages with Nested Paths

When validating nested objects and arrays of objects, error messages contain the full path to the erroneous field:

```php
class Address
{
    public function __construct(
        public string $street,
        public string $city,
        public string $country,
        public string $postalCode
    ) {}
}

class User
{
    public function __construct(
        public int $id,
        public string $name,
        #[MapProperty(arrayOf: Address::class)]
        public array $addresses
    ) {}
}

$data = [
    'id' => 1,
    'name' => 'John Doe',
    'addresses' => [
        [
            'street' => '123 Main St',
            'city' => 'New York',
            'country' => 'US',
            'postalCode' => '10001'
        ],
        [
            'street' => '456 Oak Ave',
            'city' => 'Los Angeles',
            // Missing 'country'!
            'postalCode' => '90001'
        ]
    ]
];

try {
    $user = $mapper->fromArray($data, User::class);
} catch (ValidationException $e) {
    $errors = $e->getErrors();
    // ['addresses[1].country' => "Missing required parameter 'country' at path 'addresses[1].country'"]

    // You can see exactly that the problem is in the second address (index 1), in the 'country' field
}
```

## Export to API Response Format

`ValidationException` provides a `toApiResponse()` method for structured JSON output:

```php
try {
    $user = $mapper->fromArray($data, User::class);
} catch (ValidationException $e) {
    // Export to structured format
    $response = $e->toApiResponse();

    // Result:
    // [
    //     'message' => 'Invalid request data',
    //     'code' => 422,
    //     'context' => [
    //         'validation' => [
    //             'addresses[1].country' => [
    //                 "Missing required parameter 'country' at path 'addresses[1].country'"
    //             ]
    //         ]
    //     ]
    // ]

    // Custom message and code
    $response = $e->toApiResponse('Validation failed', 400);

    // For API response
    return response()->json($response, $response['code']);
}
```

Benefits of this format:
- **Full paths**: `addresses[1].country` instead of just `country` - you know exactly where the problem is
- **Structured output**: Consistent format for all API responses
- **All errors at once**: Get all validation errors in one response
- **Array values**: Each field has an array of error messages, allowing multiple errors per field

## Custom Messages with Placeholders

A custom `message` may contain placeholders:

| Placeholder | Value |
|-------------|-------|
| `{{ name }}` | Property name |
| `{{ value }}` | The validated value (arrays of scalars joined with `, `) |
| `{{ <option> }}` | Any public option of the validator, e.g. `{{ min }}`, `{{ max }}`, `{{ choices }}` |

```php
#[Length(min: 3, message: '{{ name }} must have at least {{ min }} characters, got "{{ value }}"')]
public string $code;

#[Range(min: 1, max: 10, message: 'Quantity must be between {{ min }} and {{ max }}')]
public int $qty;

#[Choice(choices: ['red', 'green'], message: 'Choose one of: {{ choices }}')]
public string $color;
```

Unknown placeholders are left as they are; default messages do not use placeholders.

## Available Validators

### NotNull
```php
#[NotNull]
#[NotNull(message: 'Custom error message')]
public ?string $name;
```

### Range
```php
#[Range(min: 0, max: 100)]
#[Range(min: 18)] // Only minimum
#[Range(max: 65)] // Only maximum
public int $age;
```

### Length
```php
#[Length(min: 3, max: 50)]
#[Length(exact: 10)] // Exactly 10 characters
public string $username;
```

### Email
```php
#[Email]
#[Email(message: 'Please enter valid email')]
public string $email;
```

### Pattern (Regex)
```php
#[Pattern(pattern: '/^[A-Z]{3}\d{3}$/')]
#[Pattern(pattern: '/^\+\d{1,3}\s\d+$/', message: 'Invalid phone format')]
public string $code;
```

### Positive
```php
#[Positive]
public int|float $amount;
```

### Url
```php
#[Url]
public string $website;
```

### Uuid
```php
#[Uuid]
public string $id;
// Validates: "550e8400-e29b-41d4-a716-446655440000"

#[Uuid(version: 4)]
public string $uuid;
// Only accepts UUID v4
```

### Iban
```php
#[Iban]
public string $bankAccount;
// Validates: "DE89370400440532013000"
// Also accepts with spaces: "DE89 3704 0044 0532 0130 00"
```

### CreditCard
```php
#[CreditCard]
public string $cardNumber;
// Validates using Luhn algorithm

#[CreditCard(types: ['visa', 'mastercard'])]
public string $card;
// Only allows specific card types: visa, mastercard, amex, discover, diners, jcb
```

### Regex
```php
#[Regex('/^[A-Z]{3}$/')]
public string $code;
// Must be exactly 3 uppercase letters

#[Regex('/^\d{6}$/', message: 'Postal code must be 6 digits')]
public string $postalCode;
```

### MacAddress
```php
#[MacAddress]
public string $macAddress;
// Supports multiple formats:
// - Colon: "00:1A:2B:3C:4D:5E"
// - Dash: "00-1A-2B-3C-4D-5E"
// - Dot: "001A.2B3C.4D5E"
// - No separator: "001A2B3C4D5E"
```

### Count
```php
#[Count(min: 1)]              // At least 1 element
#[Count(max: 5)]              // At most 5 elements
#[Count(min: 1, max: 10)]     // Between 1 and 10
#[Count(exactly: 3)]          // Exactly 3 elements
public array $items;
```

### Valid (Recursive Validation)
```php
use Pocta\DataMapper\Validation\Valid;

class OrderRequest
{
    #[NotBlank]
    public string $orderNumber;

    #[Valid]
    public AddressDTO $shippingAddress;

    /** @var array<ItemDTO> */
    #[Valid]
    public array $items = [];
}

// Errors use dot-notation paths:
// 'shippingAddress.city' => "Property 'city' must not be blank"
// 'items[0].quantity' => "Property 'quantity' must be at least 1"
```

### Other Validators
- `Blank` - Must be empty string or null
- `NotBlank` - Must not be empty/blank
- `IsTrue` / `IsFalse` - Must be exactly true/false
- `IsNull` - Must be null
- `Type` - Must be of specific type
- `Json` - Must be valid JSON string
- `Hostname` - Must be valid hostname
- `Ip` - Must be valid IP address (supports V4/V6)
- Comparison validators: `EqualTo`, `NotEqualTo`, `IdenticalTo`, `GreaterThan`, `GreaterThanOrEqual`, `LessThan`, `LessThanOrEqual`
- Number validators: `Negative`, `NegativeOrZero`, `PositiveOrZero`, `DivisibleBy`
- Date/Time validators: `Date`, `DateTime`, `Time`, `Timezone`, `Week`
- `Choice` - Value must be one of allowed choices
- `Callback` - Custom validation function (on properties or methods)

## Callback on Methods

`#[Callback]` can be placed on class methods for cross-field validation. The method must be public, take no parameters, and return `array<string, string>` (field path => error) or `null`.

```php
class OrderRequest
{
    public bool $hasBlueAgreement = false;
    public bool $hasGoldAgreement = false;

    #[Callback]
    public function validateAgreements(): array
    {
        if (!$this->hasBlueAgreement && !$this->hasGoldAgreement) {
            return ['hasBlueAgreement' => 'You must select at least one agreement.'];
        }
        return [];
    }
}
```

## Validation Groups

Validation groups allow conditional validation — different rules based on context. All validators accept an optional `groups` parameter (default: `['Default']`).

```php
use Pocta\DataMapper\Validation\GroupSequenceProviderInterface;
use Pocta\DataMapper\Validation\NotBlank;

class ClientRequest implements GroupSequenceProviderInterface
{
    #[NotBlank] // Always validated (Default group)
    public string $entityType;

    #[NotBlank(groups: ['NaturalPerson'])]
    public ?string $firstName = null;

    #[NotBlank(groups: ['LegalEntity'])]
    public ?string $companyName = null;

    public function getGroupSequence(): array
    {
        return $this->entityType === 'legal_entity'
            ? ['Default', 'LegalEntity']
            : ['Default', 'NaturalPerson'];
    }
}

// Auto-detection via GroupSequenceProviderInterface
$validator = new Validator();
$errors = $validator->validate($client, throw: false);

// Explicit groups
$errors = $validator->validate($client, throw: false, groups: ['LegalEntity']);
```

## Combining Validators

You can combine multiple validators:

```php
class Product
{
    #[NotNull]
    #[Length(min: 3, max: 100)]
    public string $name;

    #[NotNull]
    #[Positive]
    #[Range(max: 1000000)]
    public float $price;
}
```

## Custom Validator

```php
use Pocta\DataMapper\Validation\AssertInterface;
use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class UniqueEmail implements AssertInterface
{
    public function validate(mixed $value, string $propertyName): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return "Must be string";
        }

        // Custom logic
        if (User::where('email', $value)->exists()) {
            return "Email {$value} is already taken";
        }

        return null; // Valid
    }
}

// Usage
class User
{
    #[Email]
    #[UniqueEmail]
    public string $email;
}
```

## Custom Validators with Dependency Injection

For validators that need external dependencies (repositories, API clients, services), use the `ConstraintInterface` + `ConstraintValidatorInterface` pattern. This separates the attribute (constraint) from the validation logic (validator class), allowing the validator to be resolved from a DI container.

### 1. Define the Constraint Attribute

```php
use Attribute;
use Pocta\DataMapper\Validation\ConstraintInterface;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ValidRegistrationNumber implements ConstraintInterface
{
    /** @param array<string> $groups */
    public function __construct(
        public readonly array $groups = ['Default'],
    ) {}

    public function validatedBy(): string
    {
        return RegistrationNumberValidator::class;
    }

    public function validate(mixed $value, string $propertyName): ?string
    {
        return null; // Handled by validatedBy() class
    }
}
```

### 2. Implement the Validator

The validator class receives the value, the constraint instance (for accessing parameters), and the entire object (for cross-field validation):

```php
use Pocta\DataMapper\Validation\ConstraintValidatorInterface;

class RegistrationNumberValidator implements ConstraintValidatorInterface
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly CurrentUser $currentUser,
    ) {}

    public function validate(mixed $value, object $constraint, object $object): ?string
    {
        if ($value === null) {
            return null;
        }

        $agent = $this->agentRepository->findByRegistrationNumber($value);

        if ($agent !== null) {
            return null;
        }

        return "Registration number \"{$value}\" does not exist.";
    }
}
```

### 3. Implement the Resolver

The resolver bridges the library with your DI container. Implement `ValidatorResolverInterface`:

**Nette DI:**
```php
use Nette\DI\Container;
use Pocta\DataMapper\Validation\ConstraintValidatorInterface;
use Pocta\DataMapper\Validation\ValidatorResolverInterface;

class NetteValidatorResolver implements ValidatorResolverInterface
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function resolve(string $validatorClass): ConstraintValidatorInterface
    {
        return $this->container->getByType($validatorClass);
    }
}
```

**Symfony DI:**
```php
use Psr\Container\ContainerInterface;
use Pocta\DataMapper\Validation\ConstraintValidatorInterface;
use Pocta\DataMapper\Validation\ValidatorResolverInterface;

class SymfonyValidatorResolver implements ValidatorResolverInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function resolve(string $validatorClass): ConstraintValidatorInterface
    {
        return $this->container->get($validatorClass);
    }
}
```

**Laravel DI:**
```php
use Illuminate\Contracts\Container\Container;
use Pocta\DataMapper\Validation\ConstraintValidatorInterface;
use Pocta\DataMapper\Validation\ValidatorResolverInterface;

class LaravelValidatorResolver implements ValidatorResolverInterface
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function resolve(string $validatorClass): ConstraintValidatorInterface
    {
        return $this->container->make($validatorClass);
    }
}
```

### 4. Wire It Together

Pass the resolver directly to `Mapper`:

```php
$resolver = new NetteValidatorResolver($container);

$mapper = new Mapper(
    options: MapperOptions::withAutoValidation(),
    validatorResolver: $resolver,
);
```

Or use the `Validator` standalone:

```php
$resolver = new NetteValidatorResolver($container);
$validator = new Validator(validatorResolver: $resolver);

$errors = $validator->validate($dto, throw: false);
```

Without a resolver, validators are instantiated directly (`new $validatorClass()`), which works for validators without constructor dependencies. If a validator requires dependencies and no resolver is configured, an error will be thrown at runtime.

### 5. Use in DTOs

```php
class SubmitLeadRequest
{
    #[NotBlank]
    public string $name;

    #[Length(exact: 6, groups: ['checkRecipient'])]
    #[ValidRegistrationNumber(groups: ['checkRecipient'])]
    public ?string $recipientCode = null;
}
```

### Cross-field Validation

The validator receives the entire object, so you can access other properties:

```php
#[Attribute(Attribute::TARGET_PROPERTY)]
class PasswordMatch implements ConstraintInterface
{
    public readonly array $groups;

    public function __construct(array $groups = ['Default'])
    {
        $this->groups = $groups;
    }

    public function validatedBy(): string
    {
        return PasswordMatchValidator::class;
    }

    public function validate(mixed $value, string $propertyName): ?string
    {
        return null;
    }
}

class PasswordMatchValidator implements ConstraintValidatorInterface
{
    public function validate(mixed $value, object $constraint, object $object): ?string
    {
        if ($value === $object->password) {
            return null;
        }

        return 'Passwords do not match.';
    }
}

class ChangePasswordRequest
{
    #[NotBlank]
    public string $password;

    #[PasswordMatch]
    public string $passwordConfirm;
}
```

### Accessing Constraint Parameters

The constraint instance is passed to the validator, so you can define custom parameters on the attribute:

```php
#[Attribute(Attribute::TARGET_PROPERTY)]
class MaxValue implements ConstraintInterface
{
    public function __construct(
        public readonly int $max = 100,
        public readonly array $groups = ['Default'],
    ) {}

    public function validatedBy(): string { return MaxValueValidator::class; }
    public function validate(mixed $value, string $propertyName): ?string { return null; }
}

class MaxValueValidator implements ConstraintValidatorInterface
{
    public function validate(mixed $value, object $constraint, object $object): ?string
    {
        if ($constraint instanceof MaxValue && is_int($value) && $value > $constraint->max) {
            return "Value must be at most {$constraint->max}.";
        }

        return null;
    }
}
```
