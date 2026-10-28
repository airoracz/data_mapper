<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\MapperOptions;
use Pocta\DataMapper\Exceptions\ValidationException;
use Tests\Fixtures\TestClass;

class MapperOptionsTest extends TestCase
{
    public function testDefaultOptionsValues(): void
    {
        $options = new MapperOptions();

        $this->assertFalse($options->autoValidate);
        $this->assertFalse($options->strictMode);
        $this->assertTrue($options->throwOnMissingData);
        $this->assertFalse($options->skipNullValues);
        $this->assertFalse($options->preserveNumericStrings);
    }

    public function testCustomOptionsValues(): void
    {
        $options = new MapperOptions(
            autoValidate: true,
            strictMode: true,
            throwOnMissingData: false,
            skipNullValues: true,
            preserveNumericStrings: true
        );

        $this->assertTrue($options->autoValidate);
        $this->assertTrue($options->strictMode);
        $this->assertFalse($options->throwOnMissingData);
        $this->assertTrue($options->skipNullValues);
        $this->assertTrue($options->preserveNumericStrings);
    }

    public function testWithAutoValidationFactory(): void
    {
        $options = MapperOptions::withAutoValidation();

        $this->assertTrue($options->autoValidate);
        $this->assertFalse($options->strictMode);
    }

    public function testWithStrictModeFactory(): void
    {
        $options = MapperOptions::withStrictMode();

        $this->assertFalse($options->autoValidate);
        $this->assertTrue($options->strictMode);
    }

    public function testStrictFactory(): void
    {
        $options = MapperOptions::strict();

        $this->assertTrue($options->autoValidate);
        $this->assertTrue($options->strictMode);
    }

    public function testDevelopmentFactory(): void
    {
        $options = MapperOptions::development();

        $this->assertTrue($options->autoValidate);
        $this->assertTrue($options->strictMode);
        $this->assertTrue($options->throwOnMissingData);
    }

    public function testProductionFactory(): void
    {
        $options = MapperOptions::production();

        $this->assertFalse($options->autoValidate);
        $this->assertFalse($options->strictMode);
        $this->assertTrue($options->throwOnMissingData);
    }

    public function testWithMethod(): void
    {
        $original = new MapperOptions(
            autoValidate: false,
            strictMode: false
        );

        $modified = $original->with(
            autoValidate: true,
            strictMode: true
        );

        // Original should be unchanged
        $this->assertFalse($original->autoValidate);
        $this->assertFalse($original->strictMode);

        // Modified should have new values
        $this->assertTrue($modified->autoValidate);
        $this->assertTrue($modified->strictMode);
    }

    public function testWithMethodPartialUpdate(): void
    {
        $original = new MapperOptions(
            autoValidate: true,
            strictMode: false,
            skipNullValues: true
        );

        $modified = $original->with(strictMode: true);

        // Only strictMode should change
        $this->assertTrue($modified->autoValidate);
        $this->assertTrue($modified->strictMode);
        $this->assertTrue($modified->skipNullValues);
    }

    public function testMapperWithOptions(): void
    {
        $options = MapperOptions::strict();
        $mapper = new Mapper($options);

        $this->assertTrue($mapper->isAutoValidate());
        $this->assertTrue($mapper->isStrictMode());
    }

    public function testMapperWithOptionsStrictModeValidation(): void
    {
        $options = MapperOptions::withStrictMode();
        $mapper = new Mapper($options);

        $data = [
            'id' => 1,
            'name' => 'John',
            'active' => true,
            'user_age' => 30,
            'is_admin' => false,
            'unmappedProperty' => 'test',
            'unknown_key' => 'not allowed'
        ];

        $this->expectException(ValidationException::class);
        $mapper->fromArray($data, TestClass::class);
    }

    public function testMapperWithDevelopmentOptions(): void
    {
        $options = MapperOptions::development();
        $mapper = new Mapper($options);

        // Should fail with unknown key in strict mode
        $data = [
            'id' => 1,
            'name' => 'John',
            'active' => true,
            'user_age' => 30,
            'is_admin' => false,
            'unmappedProperty' => 'test',
            'extra' => 'value'
        ];

        $this->expectException(ValidationException::class);
        $mapper->fromArray($data, TestClass::class);
    }

    public function testMapperWithProductionOptions(): void
    {
        $options = MapperOptions::production();
        $mapper = new Mapper($options);

        // Should work fine - production is lenient
        $data = [
            'id' => 1,
            'name' => 'John',
            'active' => true,
            'user_age' => 30,
            'is_admin' => false,
            'unmappedProperty' => 'test',
            'extra' => 'ignored'
        ];

        $object = $mapper->fromArray($data, TestClass::class);
        $this->assertInstanceOf(TestClass::class, $object);
    }

    public function testApiPreset(): void
    {
        $options = MapperOptions::api();

        $this->assertTrue($options->autoValidate);
        $this->assertTrue($options->strictMode);
        $this->assertTrue($options->throwOnMissingData);
        $this->assertTrue($options->publicPropertiesOnly);
        $this->assertTrue($options->symmetricPropertyPaths);
        $this->assertSame(64, $options->jsonDepth);
        $this->assertFalse($options->skipNullValues);
        $this->assertFalse($options->preserveNumericStrings);
        $this->assertFalse($options->parseFormattedNumbers);
    }

    public function testApiPresetCanBeAdjusted(): void
    {
        $options = MapperOptions::api()->with(parseFormattedNumbers: true);

        $this->assertTrue($options->parseFormattedNumbers);
        $this->assertTrue($options->publicPropertiesOnly);
    }
}
