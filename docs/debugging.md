# Debug & Profiling

[← Back to README](../README.md)

Data Mapper includes a powerful debug and profiling system for analyzing and optimizing the performance of your mapping operations.

## Basic Usage

```php
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\Debug\Debugger;
use Pocta\DataMapper\Debug\Profiler;

// Create debugger and profiler
$debugger = new Debugger(enabled: true, debugMode: true);
$profiler = new Profiler(enabled: true);

// Create mapper with debugger and profiler
$mapper = new Mapper(
    debugger: $debugger,
    profiler: $profiler
);

// Normal mapper usage
$user = $mapper->fromArray($data, User::class);
```

> **Long-running processes** (RoadRunner, Swoole, queue workers): the debugger keeps every log entry in memory
> by default. Cap it with `new Debugger(maxLogEntries: 1000)` - only the newest entries are kept.

## Debugger - What It Logs and How to Get Data

The debugger records **all important operations** during mapping:

### 1. Mapping Operations

**What it logs:**
- All calls to `fromArray()`, `toArray()`, `fromJson()`, `toJson()`
- Type of input data (array, object, string)
- Target class for denormalization

**How to get data:**

```php
// Get all logs
$logs = $debugger->getLogs();
// Result: [
//     ['type' => 'operation', 'operation' => 'fromArray', 'className' => 'User', 'dataType' => 'array', 'timestamp' => 1234567890.123],
//     ['type' => 'operation', 'operation' => 'toJson', 'className' => null, 'dataType' => 'object', 'timestamp' => 1234567890.456],
//     ...
// ]

// Get only mapping operations
$operations = $debugger->getLogsByType('operation');

// What it tells you:
// - How many times and when individual mapping methods were called
// - What data (types) you're working with
// - Which classes you map most frequently
```

### 2. Event Tracking

**What it logs:**
- All dispatched events (PreDenormalizeEvent, PostDenormalizeEvent, etc.)
- Count of individual events

**How to get data:**

```php
// Get events
$events = $debugger->getLogsByType('event');

// Event statistics
$stats = $debugger->getEventStats();
// Result: [
//     'Pocta\DataMapper\Events\PreDenormalizeEvent' => 15,
//     'Pocta\DataMapper\Events\PostDenormalizeEvent' => 15,
//     'Pocta\DataMapper\Events\ValidationEvent' => 5,
//     ...
// ]

// What it tells you:
// - Which events are triggered and how often
// - How many active listeners you have
// - Whether your event listeners work correctly
```

### 3. Metadata and Cache Info

**What it logs:**
- Metadata loading for individual classes
- Cache hits (metadata taken from cache)
- Cache misses (metadata had to be loaded)

**How to get data:**

```php
// Get metadata logs
$metadata = $debugger->getLogsByType('metadata');
// Result: [
//     ['type' => 'metadata', 'className' => 'User', 'fromCache' => false, 'propertyCount' => 5, 'timestamp' => ...],
//     ['type' => 'metadata', 'className' => 'User', 'fromCache' => true, 'propertyCount' => 5, 'timestamp' => ...],
//     ...
// ]

// Cache operations
$cache = $debugger->getLogsByType('cache');

// What it tells you:
// - Which classes are mapped most frequently
// - How efficiently cache works (how many hits vs. misses)
// - How many properties individual classes have
```

### 4. Summary Overview

**How to get data:**

```php
$summary = $debugger->getSummary();
// Result: [
//     'totalLogs' => 150,              // Total number of records
//     'operations' => 50,              // Number of mapping operations
//     'events' => 80,                  // Number of events
//     'eventTypes' => 6,               // Number of different event types
//     'metadataLoads' => 15,           // Number of metadata loads
//     'cacheHits' => 35,               // Number of cache hits
//     'cacheMisses' => 15,             // Number of cache misses
//     'cacheHitRatio' => 70.0          // Cache hit ratio in %
// ]

// What it tells you:
// - Overall mapper activity
// - Cache efficiency (70% hit ratio = good!)
// - Where optimization is possible
```

### Debug Mode - Detailed Output

```php
// Debug mode with output to STDERR
$debugger = new Debugger(enabled: true, debugMode: true);

// Each operation is printed:
// [DEBUG] Operation: fromArray -> User
// [DEBUG] Data: Array(...)
// [DEBUG] Event: Pocta\DataMapper\Events\PreDenormalizeEvent
// [DEBUG] Metadata [CACHE HIT]: User (5 properties)

// Change output stream
$file = fopen('/tmp/debug.log', 'w');
$debugger->setOutputStream($file);

// Disable debug mode
$debugger->setDebugMode(false);
```

## Profiler - Performance Measurement

The profiler **measures time and memory** of all operations:

### 1. What It Measures

- **Operation time**: How long each operation takes (microsecond precision)
- **Memory usage**: How much memory each operation consumes
- **Call count**: How many times an operation was called
- **Averages**: Average time and memory per operation

**Tracked operations:**
- `fromJson` - JSON → object (including JSON parsing)
- `fromArray` - Array → object
- `toJson` - Object → JSON (including JSON encoding)
- `toArray` - Object → array
- `denormalize` - Denormalization itself (without pre/post events)
- `normalize` - Normalization itself
- `validation` - Object validation (if enabled)

### 2. How to Get Data

```php
// Metrics for specific operation
$metrics = $profiler->getMetrics('fromArray');
// Result: [
//     'count' => 50,                   // Number of calls
//     'totalTime' => 0.234,            // Total time (seconds)
//     'totalMemory' => 1024000,        // Total memory (bytes)
//     'avgTime' => 0.00468,            // Average time (seconds)
//     'avgMemory' => 20480.0           // Average memory (bytes)
// ]

// What it tells you:
// - fromArray was called 50 times
// - Total took 234ms
// - Average 4.68ms per call
// - Average consumes 20KB memory
```

```php
// All metrics at once
$allMetrics = $profiler->getAllMetrics();
// Result: [
//     'fromArray' => ['count' => 50, 'totalTime' => 0.234, ...],
//     'toArray' => ['count' => 30, 'totalTime' => 0.156, ...],
//     'denormalize' => ['count' => 50, 'totalTime' => 0.189, ...],
//     ...
// ]

// What it tells you:
// - Overview of all operations
// - Which operation is slowest
// - Which operation consumes most memory
```

```php
// Summary statistics
$summary = $profiler->getSummary();
// Result: [
//     'totalOperations' => 130,        // Total number of operations
//     'totalTime' => 0.579,            // Total time (579ms)
//     'totalMemory' => 2560000,        // Total memory (2.56MB)
//     'peakMemory' => 12582912         // Peak memory (12MB)
// ]

// What it tells you:
// - Overall performance
// - Application memory footprint
// - Where to optimize (if totalTime is high)
```

### 3. Formatted Report

```php
// Text report (human-readable)
$report = $profiler->getReport();
echo $report->toText();

// Output:
// === PROFILING REPORT ===
//
// Summary:
//   Total Operations: 130
//   Total Time: 579.00 ms
//   Total Memory: 2.44 MB
//   Peak Memory: 12.00 MB
//
// Detailed Metrics:
// ----------------------------------------------------------------------------------------------------
// Operation                                | Count    | Total Time      | Avg Time        | Avg Memory
// ----------------------------------------------------------------------------------------------------
// fromArray                                | 50       | 234.00 ms       | 4.68 ms         | 20.00 KB
// toArray                                  | 30       | 156.00 ms       | 5.20 ms         | 18.50 KB
// denormalize                              | 50       | 189.00 ms       | 3.78 ms         | 15.00 KB
// ----------------------------------------------------------------------------------------------------

// What it tells you:
// - Which operations are slowest
// - Where to optimize
// - What the memory overhead is
```

```php
// JSON report (for monitoring/logging)
$jsonReport = $report->toJson();
// Result: Structured JSON with metrics

// Array report (for programmatic processing)
$arrayReport = $report->toArray();

// What to do with it:
// - Save to log for long-term analysis
// - Send to monitoring system (Grafana, New Relic)
// - Compare performance between versions
```

### 4. Sorting and Top Operations

```php
$report = $profiler->getReport();

// Top 5 slowest operations
$slowest = $report->getTopByTime(5);

// Top 5 operations with most memory
$memoryHeavy = $report->getTopByMemory(5);

// Sort by call count
$mostCalled = $report->getSortedByCount();

// What it tells you:
// - Which operations to optimize first
// - Where memory leaks are
// - Which operations are called unnecessarily often
```

### 5. Custom Measurement

```php
// Measure custom operation
$profiler->start('custom_operation');
// ... your code ...
$profiler->stop('custom_operation');

// Or with callable
$result = $profiler->profile('my_task', function() {
    // Some heavy computation
    return expensiveOperation();
});

// Metrics
$metrics = $profiler->getMetrics('my_task');
```

## Practical Examples

### Example 1: Performance Debugging

```php
$debugger = new Debugger(enabled: true);
$profiler = new Profiler(enabled: true);
$mapper = new Mapper(debugger: $debugger, profiler: $profiler);

// Your mapping
foreach ($bigDataset as $item) {
    $mapper->fromArray($item, Product::class);
}

// Analysis
$summary = $profiler->getSummary();
$cacheStats = $debugger->getSummary();

if ($summary['totalTime'] > 1.0) {
    echo "Mapping is slow ({$summary['totalTime']}s)\n";

    $report = $profiler->getReport();
    echo $report->toText();

    // Cache problem?
    if ($cacheStats['cacheHitRatio'] < 50) {
        echo "Cache hit ratio is low ({$cacheStats['cacheHitRatio']}%)\n";
        echo "Consider using persistent cache (Redis/Memcached)\n";
    }
}
```

### Example 2: Production Monitoring

```php
$profiler = new Profiler(enabled: true);
$mapper = new Mapper(profiler: $profiler);

// Your API endpoint
$data = processRequest();
$result = $mapper->fromArray($data, Response::class);

// Log to monitoring system
$metrics = $profiler->getSummary();
if ($metrics['totalTime'] > 0.1) {  // 100ms threshold
    logger()->warning('Slow mapper operation', [
        'time' => $metrics['totalTime'],
        'memory' => $metrics['totalMemory'],
        'operations' => $metrics['totalOperations']
    ]);
}
```

### Example 3: Development Debugging

```php
// Enable only in dev mode
$debugger = new Debugger(
    enabled: $_ENV['APP_ENV'] === 'development',
    debugMode: true
);

$mapper = new Mapper(debugger: $debugger);

// Console output during development
// [DEBUG] Operation: fromArray -> User
// [DEBUG] Event: PreDenormalizeEvent
// [DEBUG] Metadata [CACHE MISS]: User (12 properties)
```

### Example 4: Complete Analysis

```php
$debugger = new Debugger(enabled: true);
$profiler = new Profiler(enabled: true);
$mapper = new Mapper(
    autoValidate: true,
    debugger: $debugger,
    profiler: $profiler
);

// Your operation
$user = $mapper->fromArray($userData, User::class);

// === DEBUGGER ANALYSIS ===
echo "=== DEBUGGER REPORT ===\n";
$debugSummary = $debugger->getSummary();
echo "Total logs: {$debugSummary['totalLogs']}\n";
echo "Operations: {$debugSummary['operations']}\n";
echo "Events dispatched: {$debugSummary['events']}\n";
echo "Cache hit ratio: {$debugSummary['cacheHitRatio']}%\n\n";

// Event breakdown
echo "Event breakdown:\n";
foreach ($debugger->getEventStats() as $event => $count) {
    $shortName = substr($event, strrpos($event, '\\') + 1);
    echo "  - {$shortName}: {$count}x\n";
}

// === PROFILER ANALYSIS ===
echo "\n=== PROFILER REPORT ===\n";
$report = $profiler->getReport();
echo $report->toText();

// Specific metrics
if ($metrics = $profiler->getMetrics('validation')) {
    echo "\nValidation takes: {$metrics['avgTime']}s per object\n";
}

// What the entire output tells you:
// 1. How many operations occurred
// 2. How efficient the cache is
// 3. Which events were triggered
// 4. How much time each operation takes
// 5. Where performance bottlenecks are
// 6. How much memory is consumed
```

## When to Use Debug vs. Profiling

**Use Debugger when:**
- You need to know **WHAT** is happening
- You want to see operation flow
- You're debugging event listeners
- You're analyzing cache efficiency
- You need an audit trail

**Use Profiler when:**
- You need to know **HOW FAST** it runs
- You're optimizing performance
- You're looking for memory leaks
- You're measuring impact of changes
- You're monitoring production performance

**Use both when:**
- Complex performance problem
- Optimizing entire system
- Long-term monitoring
- Production debugging

## Performance Overhead

- **Debugger**: Minimal (~1-2% overhead)
- **Profiler**: Minimal (~2-3% overhead)
- **Debug mode**: Medium (~5-10% overhead due to I/O)

**Recommendations:**
- Production: Profiler enabled, Debugger disabled
- Development: Both enabled with debug mode
- Testing: Both disabled (for clean metrics)
