<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Pocta\DataMapper\Mapper;
use Pocta\DataMapper\Exceptions\ValidationException;

class ValidationExceptionTest extends TestCase
{
    private Mapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new Mapper();
    }

    public function testMultipleValidationErrors(): void
    {
        $data = [
            'id' => 'not a number',
            'name' => 123,  // Name is string, this should work with type coercion
            'price' => 'not a price',
            'createdAt' => 'invalid-date',
        ];

        try {
            $this->mapper->fromArray($data, ValidationTestClass::class);
            $this->fail('Expected ValidationException to be thrown');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();

            // Check that we have multiple errors
            $this->assertGreaterThan(1, count($errors));

            // Check that specific fields have errors
            $this->assertTrue($e->hasError('id'));
            $this->assertTrue($e->hasError('price'));
            $this->assertTrue($e->hasError('createdAt'));

            // Check error messages
            $this->assertStringContainsString('integer', $e->getError('id') ?? '');
            $this->assertStringContainsString('float', $e->getError('price') ?? '');
            $this->assertStringContainsString('datetime', $e->getError('createdAt') ?? '');

            // Check the main message mentions multiple errors
            $this->assertStringContainsString('error(s)', $e->getMessage());
        }
    }

    public function testSingleValidationError(): void
    {
        $data = [
            'id' => 'not a number',
            'name' => 'Valid Name',
            'price' => 19.99,
            'createdAt' => '2024-10-28T10:30:00+00:00',
        ];

        try {
            $this->mapper->fromArray($data, ValidationTestClass::class);
            $this->fail('Expected ValidationException to be thrown');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();

            // Check that we have only one error
            $this->assertCount(1, $errors);

            // Check that id has error
            $this->assertTrue($e->hasError('id'));
            $this->assertFalse($e->hasError('name'));
            $this->assertFalse($e->hasError('price'));

            // For single error, message should be simple
            $this->assertStringNotContainsString('error(s)', $e->getMessage());
        }
    }

    public function testMissingRequiredFields(): void
    {
        $data = [
            'id' => 1,
            // name is missing
            // price is missing
        ];

        try {
            $this->mapper->fromArray($data, ValidationTestClassWithConstructor::class);
            $this->fail('Expected ValidationException to be thrown');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();

            // Check that we have errors for missing fields
            $this->assertGreaterThanOrEqual(1, count($errors));

            // The exact behavior depends on whether properties have default values
            // Just ensure exception is thrown
            $this->assertNotEmpty($errors);
        }
    }

    public function testToApiResponseSingleError(): void
    {
        $data = [
            'id' => 'not a number',
            'name' => 'Valid Name',
            'price' => 19.99,
            'createdAt' => '2024-10-28T10:30:00+00:00',
        ];

        try {
            $this->mapper->fromArray($data, ValidationTestClass::class);
            $this->fail('Expected ValidationException to be thrown');
        } catch (ValidationException $e) {
            $response = $e->toApiResponse();

            // Check structure
            $this->assertArrayHasKey('message', $response);
            $this->assertArrayHasKey('code', $response);
            $this->assertArrayHasKey('context', $response);
            $this->assertIsArray($response['context']);
            $this->assertArrayHasKey('validation', $response['context']);

            // Check default values
            $this->assertSame('Invalid request data', $response['message']);
            $this->assertSame(422, $response['code']);

            // Check validation structure
            /** @var array<string, array<string>> $validation */
            $validation = $response['context']['validation'];
            $this->assertArrayHasKey('id', $validation);
            $this->assertCount(1, $validation['id']);
            $this->assertStringContainsString('integer', $validation['id'][0]);
        }
    }

    public function testToApiResponseMultipleErrors(): void
    {
        $data = [
            'id' => 'not a number',
            'name' => 123,
            'price' => 'not a price',
            'createdAt' => 'invalid-date',
        ];

        try {
            $this->mapper->fromArray($data, ValidationTestClass::class);
            $this->fail('Expected ValidationException to be thrown');
        } catch (ValidationException $e) {
            $response = $e->toApiResponse('Validation failed', 400);

            // Check custom message and code
            $this->assertSame('Validation failed', $response['message']);
            $this->assertSame(400, $response['code']);

            // Check validation has multiple errors
            $this->assertIsArray($response['context']);
            /** @var array<string, array<string>> $validation */
            $validation = $response['context']['validation'];
            $this->assertGreaterThan(1, count($validation));

            // Each error should be an array
            foreach ($validation as $fieldErrors) {
                $this->assertNotEmpty($fieldErrors);
            }
        }
    }

    public function testToApiResponseWithNestedPath(): void
    {
        // Create ValidationException manually with nested path
        $errors = [
            'addresses[0].city' => "Missing required parameter 'city' at path 'addresses[0].city'",
            'addresses[1].country' => "Missing required parameter 'country' at path 'addresses[1].country'",
        ];

        $exception = new ValidationException($errors);
        $response = $exception->toApiResponse();

        // Check that nested paths are preserved
        $this->assertIsArray($response['context']);
        /** @var array<string, array<string>> $validation */
        $validation = $response['context']['validation'];
        $this->assertArrayHasKey('addresses[0].city', $validation);
        $this->assertArrayHasKey('addresses[1].country', $validation);
    }

    public function testPreviousExceptionIsKept(): void
    {
        $previous = new \RuntimeException('root cause');

        $exception = new ValidationException(['name' => 'Required'], $previous);

        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame('Required', $exception->getMessage());
    }

    public function testPreviousIsOptional(): void
    {
        $this->assertNull((new ValidationException(['name' => 'Required']))->getPrevious());
    }

    public function testJsonEncodesAsApiResponse(): void
    {
        $exception = new ValidationException(['name' => 'Required', 'items[0].qty' => 'Must be positive']);

        $this->assertInstanceOf(\JsonSerializable::class, $exception);
        $this->assertSame(
            json_encode($exception->toApiResponse()),
            json_encode($exception)
        );
    }
}

class ValidationTestClass
{
    public int $id;
    public string $name;
    public float $price;
    public \DateTimeImmutable $createdAt;
}

class ValidationTestClassWithConstructor
{
    public function __construct(
        public int $id,
        public string $name,
        public float $price
    ) {
    }
}
