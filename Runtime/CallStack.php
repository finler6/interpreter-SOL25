<?php

declare(strict_types=1);

namespace IPP\Student\Runtime;

use IPP\Student\Runtime\Frame;
use IPP\Core\Exception\InternalErrorException;

class CallStack
{
    /** @var array<int, Frame> */
    private array $frames = [];

    /**
     * Constructor for the CallStack class.
     * Initializes an empty call stack.
     */
    public function __construct()
    {
        $this->frames = [];
    }

    /**
     * Pushes a new frame onto the call stack.
     * @param Frame $frame The frame to push onto the stack.
     */
    public function push(Frame $frame): void
    {
        $this->frames[] = $frame;
    }

    /**
     * Pops the top frame from the call stack.
     * @return Frame The popped frame.
     * @throws InternalErrorException If the stack is empty.
     */
    public function pop(): Frame
    {
        if ($this->isEmpty()) {
            throw new InternalErrorException("Call stack underflow: cannot pop from an empty stack.");
        }
        /** @var Frame $frame */
        $frame = array_pop($this->frames);
        return $frame;
    }

    /**
     * Gets the current frame (the top frame of the stack) without removing it.
     * @return Frame The current frame.
     * @throws InternalErrorException If the stack is empty.
     */
    public function getCurrentFrame(): Frame
    {
        if ($this->isEmpty()) {
            throw new InternalErrorException("Call stack is empty: cannot get the current frame.");
        }
        /** @var Frame $frame */
        $frame = end($this->frames);
        return $frame;
    }

    /**
     * Checks if the call stack is empty.
     * @return bool True if the stack is empty, false otherwise.
     */
    public function isEmpty(): bool
    {
        return empty($this->frames);
    }

    /**
     * Gets the number of frames in the call stack.
     * @return int The depth of the call stack.
     */
    public function depth(): int
    {
        return count($this->frames);
    }
}
