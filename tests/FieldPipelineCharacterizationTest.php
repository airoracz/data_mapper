<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Attributes\Filters\StringToUpperFilter;
use Pocta\DataMapper\Attributes\Filters\StringTrimFilter;
use Pocta\DataMapper\Attributes\HydrationMode;
use Pocta\DataMapper\Attributes\MapProperty;
use Pocta\DataMapper\Attributes\MapPropertyWithFunction;
use Pocta\DataMapper\Exceptions\ValidationException;
use Pocta\DataMapper\Mapper;

/**
 * Characterization of the per-field input pipeline for constructor parameters and properties:
 * key/path resolution, error messages, hydrators and filters.
 */
class FieldPipelineCharacterizationTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     * @param class-string $class
     * @return array<string, string>
     */
    private function errorsOf(array $data, string $class): array
    {
        try {
            (new Mapper())->fromArray($data, $class);
        } catch (ValidationException $e) {
            return $e->getErrors();
        }
        $this->fail('Expected ValidationException');
    }

    public function testInvalidPathSyntaxOnParameter(): void
    {
        $errors = $this->errorsOf(['a' => ['x' => 1]], PipelineBadPathCtor::class);

        $this->assertSame(
            ['a[x]' => "Invalid property path syntax for parameter 'value': Invalid path syntax: array index must be numeric in 'a[x]'"],
            $errors
        );
    }

    public function testInvalidPathSyntaxOnProperty(): void
    {
        $errors = $this->errorsOf(['a' => ['x' => 1]], PipelineBadPathProp::class);

        $this->assertSame(
            ['a[x]' => "Invalid property path syntax for property 'value': Invalid path syntax: array index must be numeric in 'a[x]'"],
            $errors
        );
    }

    public function testMissingRequiredMessages(): void
    {
        $this->assertSame(
            ['title' => "Missing required parameter 'title' at path 'title'"],
            $this->errorsOf([], PipelineRequiredCtor::class)
        );
        $this->assertSame(
            ['title' => "Missing required property 'title' at path 'title'"],
            $this->errorsOf([], PipelineRequiredProp::class)
        );
    }

    public function testHydratorRunsForMissingKey(): void
    {
        $mapper = new Mapper();

        $this->assertSame('computed:x', $mapper->fromArray(['src' => 'x'], PipelineHydratedCtor::class)->value);
        $this->assertSame('computed:y', $mapper->fromArray(['src' => 'y'], PipelineHydratedProp::class)->value);
    }

    public function testFiltersRunBeforeAndAfterTyping(): void
    {
        $mapper = new Mapper();

        $this->assertSame('ABC', $mapper->fromArray(['name' => '  abc '], PipelineFilteredCtor::class)->name);
        $this->assertSame('ABC', $mapper->fromArray(['name' => '  abc '], PipelineFilteredProp::class)->name);
    }

    public function testTypeErrorKeepsPropertyUnsetAndReportsPath(): void
    {
        $this->assertSame(
            ['qty' => "Cannot cast value of field 'qty' to integer"],
            $this->errorsOf(['qty' => 'abc'], PipelineTypedProp::class)
        );
        $this->assertSame(
            ['qty' => "Cannot cast value of field 'qty' to integer"],
            $this->errorsOf(['qty' => 'abc'], PipelineTypedCtor::class)
        );
    }
}

class PipelineBadPathCtor
{
    public function __construct(#[MapProperty(path: 'a[x]')] public ?int $value = null)
    {
    }
}

class PipelineBadPathProp
{
    #[MapProperty(path: 'a[x]')]
    public ?int $value = null;
}

class PipelineRequiredCtor
{
    public function __construct(public string $title)
    {
    }
}

class PipelineRequiredProp
{
    public string $title;
}

class PipelineHydratedCtor
{
    public function __construct(
        #[MapPropertyWithFunction([PipelineHydratedCtor::class, 'compute'], HydrationMode::PARENT)]
        public string $value = '',
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function compute(array $payload): string
    {
        return 'computed:' . (is_string($payload['src'] ?? null) ? $payload['src'] : '');
    }
}

class PipelineHydratedProp
{
    #[MapPropertyWithFunction([PipelineHydratedCtor::class, 'compute'], HydrationMode::PARENT)]
    public string $value = '';
}

class PipelineFilteredCtor
{
    public function __construct(#[StringTrimFilter] #[StringToUpperFilter] public string $name = '')
    {
    }
}

class PipelineFilteredProp
{
    #[StringTrimFilter]
    #[StringToUpperFilter]
    public string $name = '';
}

class PipelineTypedProp
{
    public int $qty = 0;
}

class PipelineTypedCtor
{
    public function __construct(public int $qty = 0)
    {
    }
}
