<?php

declare(strict_types=1);

namespace IPP\Student\Exception;

use IPP\Core\ReturnCode;
use Throwable;

class ValueException extends RuntimeErrorException
{
    public function __construct(string $message = "Bad argument value", ?Throwable $previous = null)
    {
        parent::__construct($message, ReturnCode::INTERPRET_VALUE_ERROR, $previous);
    }
}
