<?php

declare(strict_types=1);

namespace IPP\Student\Exception;

use IPP\Student\Values\BlockValue;
use Throwable;

class MethodRequiresExecutionException extends \Exception
{
    public BlockValue $blockValue;

    public function __construct(BlockValue $blockValue, ?Throwable $previous = null)
    {
        $this->blockValue = $blockValue;
        parent::__construct("Method requires interpreter execution", 0, $previous);
    }
}
