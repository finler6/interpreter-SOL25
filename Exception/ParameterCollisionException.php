<?php

declare(strict_types=1);

namespace IPP\Student\Exception;

use IPP\Core\ReturnCode;
use Throwable;

class ParameterCollisionException extends RuntimeErrorException
{
    public function __construct(
        string $message = "Attempt to assign to a parameter or use reserved name",
        ?Throwable $previous = null
    ) {
        parent::__construct($message, ReturnCode::PARSE_COLLISION_ERROR, $previous);
    }
}
