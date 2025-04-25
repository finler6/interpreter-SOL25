<?php

declare(strict_types=1);

namespace IPP\Student\Exception;

use IPP\Core\ReturnCode;
use Throwable;

class UndefinedVariableException extends RuntimeErrorException
{
    public function __construct(
        string $message = "Undefined variable, parameter or keyword accessed",
        ?Throwable $previous = null
    ) {
        parent::__construct($message, ReturnCode::PARSE_UNDEF_ERROR, $previous);
    }
}
