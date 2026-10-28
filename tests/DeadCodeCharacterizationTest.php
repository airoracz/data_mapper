<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Events\DenormalizationErrorEvent;
use Pocta\DataMapper\Exceptions\ValidationException;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\MapperOptions;

class DeadCodeCharacterizationTest extends TestCase
{
    public function testSuppressExceptionDoesNotPreventRethrow(): void
    {
        $mapper = new Mapper();
        $mapper->addEventListener(DenormalizationErrorEvent::class, function (DenormalizationErrorEvent $event): void {
            $event->suppressException();
        });

        $this->expectException(ValidationException::class);

        $mapper->fromArray([], DeadCodeRequiredDto::class);
    }

    public function testDevelopmentPresetEqualsStrictPreset(): void
    {
        $this->assertEquals(MapperOptions::strict(), MapperOptions::development());
    }

    public function testProductionPresetEqualsDefaultOptions(): void
    {
        $this->assertEquals(new MapperOptions(), MapperOptions::production());
    }
}

class DeadCodeRequiredDto
{
    public string $name;
}
