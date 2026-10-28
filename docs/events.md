# Event System

[← Back to README](../README.md)

The Event System provides hooks for custom logic during mapping. You can listen to events and modify data or objects at various stages of the process.

## Available Events

### 1. PreDenormalizeEvent
Triggered before denormalization (array → object):

```php
use Pocta\DataMapper\Events\PreDenormalizeEvent;

$mapper->addEventListener(PreDenormalizeEvent::class, function(PreDenormalizeEvent $event) {
    // Access data
    $data = $event->data;
    $className = $event->className;

    // Modify data before mapping
    $event->data['created_at'] = date('Y-m-d H:i:s');

    // Stop propagation (other listeners won't run)
    $event->stopPropagation();
});
```

### 2. PostDenormalizeEvent
Triggered after successful denormalization:

```php
use Pocta\DataMapper\Events\PostDenormalizeEvent;

$mapper->addEventListener(PostDenormalizeEvent::class, function(PostDenormalizeEvent $event) {
    // Access created object
    $object = $event->object;
    $originalData = $event->originalData;

    // Modify object
    if ($object instanceof User) {
        $object->lastMappedAt = new DateTime();
    }

    // Replace object with another
    $event->setObject($modifiedObject);
});
```

### 3. PreNormalizeEvent
Triggered before normalization (object → array):

```php
use Pocta\DataMapper\Events\PreNormalizeEvent;

$mapper->addEventListener(PreNormalizeEvent::class, function(PreNormalizeEvent $event) {
    $object = $event->object;

    // Modify object before conversion
    if ($object instanceof Product) {
        $object->price = round($object->price, 2);
    }
});
```

### 4. PostNormalizeEvent
Triggered after normalization:

```php
use Pocta\DataMapper\Events\PostNormalizeEvent;

$mapper->addEventListener(PostNormalizeEvent::class, function(PostNormalizeEvent $event) {
    $data = $event->data;
    $originalObject = $event->originalObject;

    // Add extra data to output
    $event->data['_type'] = $event->getClassName();
    $event->data['_timestamp'] = time();
});
```

### 5. DenormalizationErrorEvent
Triggered on error during denormalization:

```php
use Pocta\DataMapper\Events\DenormalizationErrorEvent;

$mapper->addEventListener(DenormalizationErrorEvent::class, function(DenormalizationErrorEvent $event) {
    $exception = $event->exception;
    $data = $event->data;
    $className = $event->className;

    // Error logging
    logger()->error("Mapping failed for {$className}", [
        'data' => $data,
        'error' => $exception->getMessage()
    ]);

    // The exception is always re-thrown after this event - catch it in the caller.
});
```

### 6. ValidationEvent
Triggered during validation:

```php
use Pocta\DataMapper\Events\ValidationEvent;

$mapper->addEventListener(ValidationEvent::class, function(ValidationEvent $event) {
    $object = $event->object;
    $errors = $event->errors;

    // Custom validation logic
    if ($object instanceof User && $object->age < 0) {
        $event->addError('age', 'Age cannot be negative');
    }

    // Remove error
    $event->removeError('someField');

    // Clear all errors
    // $event->clearErrors();
});
```

## Listener Priorities

Listeners are called according to priority (higher = earlier):

```php
// High priority (100) - called first
$mapper->addEventListener(PreDenormalizeEvent::class, function($event) {
    // ...
}, priority: 100);

// Medium priority (50)
$mapper->addEventListener(PreDenormalizeEvent::class, function($event) {
    // ...
}, priority: 50);

// Low priority (0) - default
$mapper->addEventListener(PreDenormalizeEvent::class, function($event) {
    // ...
});
```

## Practical Examples

### Audit Logging

```php
$mapper->addEventListener(PostDenormalizeEvent::class, function($event) {
    auditLog()->log('object_created', [
        'class' => $event->className,
        'data' => $event->originalData,
        'user' => Auth::user()->id
    ]);
});
```

### Data Sanitization

```php
$mapper->addEventListener(PreDenormalizeEvent::class, function($event) {
    // XSS protection
    array_walk_recursive($event->data, function(&$value) {
        if (is_string($value)) {
            $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
    });
});
```

### Error Tracking

```php
$mapper->addEventListener(DenormalizationErrorEvent::class, function($event) {
    // Bugsnag, Sentry, etc.
    bugsnag()->notifyException($event->exception, [
        'data' => $event->data,
        'class' => $event->className
    ]);
});
```
