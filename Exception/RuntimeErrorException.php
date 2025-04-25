<?php

declare(strict_types=1);

namespace IPP\Student\Exception;

use IPP\Core\Exception\IPPException;
use Throwable;

class RuntimeErrorException extends IPPException
{
    public function __construct(string $message, int $code, ?Throwable $previous = null)
    {

        parent::__construct($message, $code, $previous, false);
    }
}
