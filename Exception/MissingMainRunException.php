<?php

declare(strict_types=1);

namespace IPP\Student\Exception;

use IPP\Core\ReturnCode;
use Throwable;

class MissingMainRunException extends RuntimeErrorException
{
    public function __construct(
        string $message = "Missing class 'Main' or parameterless method 'run'",
        ?Throwable $previous = null
    ) {
        parent::__construct($message, ReturnCode::PARSE_MAIN_ERROR, $previous);
    }
}
