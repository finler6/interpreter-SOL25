<?php

declare(strict_types=1);

namespace IPP\Student\Exception;

use IPP\Core\ReturnCode;
use Throwable;

class MethodArityException extends RuntimeErrorException
{
    public function __construct(
        string $message = "Method selector arity mismatch with block parameters",
        ?Throwable $previous = null
    ) {
        parent::__construct($message, ReturnCode::PARSE_ARITY_ERROR, $previous);
    }
}
