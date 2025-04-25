<?php

declare(strict_types=1);

namespace IPP\Student\Exception;

use IPP\Student\Values\BlockValue;
use IPP\Student\Values\BaseValue;
use Throwable;

class BlockRequiresExecutionException extends \Exception
{
    public BlockValue $blockValue;

    /** @var array<int, BaseValue> */
    public array $arguments;

    /**
     * @param BlockValue $blockValue
     * @param array<int, BaseValue> $arguments
     * @param Throwable|null $previous
     */
    public function __construct(BlockValue $blockValue, array $arguments, ?Throwable $previous = null)
    {
        $this->blockValue = $blockValue;
        $this->arguments = $arguments;
        parent::__construct("Block requires interpreter execution", 0, $previous);
    }
}
