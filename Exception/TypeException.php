<?php

declare(strict_types=1);

namespace IPP\Student\Exception;

use IPP\Core\ReturnCode;
use Throwable;

class TypeException extends RuntimeErrorException
{
    public function __construct(string $message = "Runtime type or semantic error", ?Throwable $previous = null)
    {
        parent::__construct($message, ReturnCode::INTERPRET_TYPE_ERROR, $previous);
    }
}
