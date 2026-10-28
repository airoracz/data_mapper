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
    public function testErrorEventIsDispatchedAndExceptionRethrown(): void
    {
        $mapper = new Mapper();
        $seen = null;
        $mapper->addEventListener(DenormalizationErrorEvent::class, function (DenormalizationErrorEvent $event) use (&$seen): void {
            $seen = $event->exception;
        });

        try {
            $mapper->fromArray([], DeadCodeRequiredDto::class);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame($e, $seen);
        }
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
