<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Exceptions;

use InvalidArgumentException;

/**
 * Exception thrown when validation fails during denormalization
 * Can contain multiple validation errors
 */
class ValidationException extends InvalidArgumentException implements \JsonSerializable
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param array<string, string> $errors Field name => error message mapping
     * @param \Throwable|null $previous Original exception, if the validation error wraps one
     */
    public function __construct(array $errors, ?\Throwable $previous = null)
    {
        $this->errors = $errors;

        $message = $this->buildMessage($errors);
        parent::__construct($message, 0, $previous);
    }

    /**
     * Gets all validation errors
     *
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Gets a specific error by field name
     *
     * @param string $fieldName
     * @return string|null
     */
    public function getError(string $fieldName): ?string
    {
        return $this->errors[$fieldName] ?? null;
    }

    /**
     * Checks if a specific field has an error
     *
     * @param string $fieldName
     * @return bool
     */
    public function hasError(string $fieldName): bool
    {
        return isset($this->errors[$fieldName]);
    }

    /**
     * Builds the exception message from errors
     *
     * @param array<string, string> $errors
     * @return string
     */
    private function buildMessage(array $errors): string
    {
        if (count($errors) === 1) {
            $fieldName = array_key_first($errors);
            return $errors[$fieldName];
        }

        $lines = ["Validation failed with " . count($errors) . " error(s):"];
        foreach ($errors as $fieldName => $error) {
            $lines[] = "  - {$fieldName}: {$error}";
        }

        return implode("\n", $lines);
    }

    /**
     * Export errors to structured array format suitable for API responses
     *
     * @param string $message Main error message (default: "Invalid request data")
     * @param int $code HTTP status code (default: 422)
     * @return array<string, mixed>
     */
    public function toApiResponse(string $message = 'Invalid request data', int $code = 422): array
    {
        $validation = [];
        foreach ($this->errors as $fieldName => $errorMessage) {
            $validation[$fieldName] = [$errorMessage];
        }

        return [
            'message' => $message,
            'code' => $code,
            'context' => [
                'validation' => $validation,
            ],
        ];
    }

    /**
     * json_encode() produces the same structure as toApiResponse() with its defaults.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toApiResponse();
    }
}
