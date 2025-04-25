<?php

declare(strict_types=1);

namespace IPP\Student\Exception;

use IPP\Core\ReturnCode;
use Throwable;

class DoNotUnderstandException extends RuntimeErrorException
{
    public function __construct(string $message = "Receiver does not understand message", ?Throwable $previous = null)
    {
        parent::__construct($message, ReturnCode::INTERPRET_DNU_ERROR, $previous);
    }
}
