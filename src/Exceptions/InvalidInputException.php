<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Exceptions;

/**
 * Input data has a shape that cannot be mapped (e.g. JSON scalar or list where an object is expected).
 *
 * It is a ValidationException, so code that already turns ValidationException into a client error
 * response handles it too; the error is reported under the path of the offending value ('' = root).
 * Still an InvalidArgumentException, as before.
 */
class InvalidInputException extends ValidationException
{
    /**
     * @param string $message Error message
     * @param string $path Path of the offending value ('' = the whole input, '[1]' = collection element)
     */
    public function __construct(string $message, string $path = '', ?\Throwable $previous = null)
    {
        parent::__construct([$path => $message], $previous);
    }
}
