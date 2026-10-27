<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Exceptions;

/**
 * Input data has a shape that cannot be mapped (e.g. JSON scalar or list where an object is expected).
 * Signals a client error, unlike a plain InvalidArgumentException which may be a programming error.
 */
class InvalidInputException extends \InvalidArgumentException
{
}
