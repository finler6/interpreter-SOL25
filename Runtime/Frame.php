<?php

declare(strict_types=1);

namespace IPP\Student\Runtime;

// Import necessary value objects and exceptions used within this file.
use IPP\Student\Values\BaseValue;
use IPP\Student\Values\NilValue;
use IPP\Student\Values\TrueValue;
use IPP\Student\Values\FalseValue;
use IPP\Student\Exception\TypeException;
use IPP\Student\Exception\UndefinedVariableException;
use IPP\Student\Exception\ParameterCollisionException;

/**
 * Represents a single execution frame (scope) on the call stack.
 * This corresponds to the execution of a single SOL25 block (either a method body or a block literal).
 * It holds the local variables defined within the block, the parameters passed to the block,
 * and the 'self' object context relevant to this scope.
 */
class Frame
{
    /**
     * Stores the local variables defined within this block.
     * Variables are created or updated by assignment statements (var := expr).
     * Keys are variable names (strings), values are the corresponding BaseValue objects.
     * @var array<string, BaseValue>
     */
    private array $variables = [];

    /**
     * Stores the parameters passed to this block when it was called.
     * Parameters are set once during frame creation and are read-only afterwards.
     * Keys are parameter names (strings), values are the passed BaseValue objects.
     * @var array<string, BaseValue>
     */
    private array $parameters = [];

    /**
     * The 'self' object context for this frame.
     * If the frame represents a method call, this holds the object instance the method was called on.
     * If the frame represents a block literal execution, this holds the 'self' captured when the block was defined.
     * Can be null if the block/method is not associated with an object context (e.g., top-level run).
     * @var BaseValue|null
     */
    private ?BaseValue $selfContext;

    /**
     * Constructor for a new execution frame.
     * @param BaseValue|null $selfContext The 'self' object for this scope, if any.
     */
    public function __construct(?BaseValue $selfContext = null)
    {
        $this->selfContext = $selfContext;
        // Variables and parameters start empty.
    }

    /**
     * Defines a parameter and its initial value within this frame.
     * Called by the Interpreter when setting up the frame before block execution.
     * Checks for redefinition within the same frame (internal error) and prevents
     * using reserved keywords as parameter names.
     *
     * @param string $name The name of the parameter (e.g., "x" from [:x|...]).
     * @param BaseValue $value The argument value passed to the block for this parameter.
     * @throws TypeException If attempting to redefine a parameter or use a reserved keyword.
     */
    public function defineParameter(string $name, BaseValue $value): void
    {
        // Internal sanity check: ensure the same parameter name isn't defined twice in this frame.
        if (array_key_exists($name, $this->parameters)) {
            // This should not happen if the parser/interpreter logic is correct.
            throw new TypeException("Internal Error: Parameter '$name' redefined in the same frame.");
        }
        // Prevent using SOL25 reserved keywords as parameter names.
        if ($name === 'self' || $name === 'super' || $name === 'nil' || $name === 'true' || $name === 'false') {
            throw new TypeException("Cannot use reserved keyword '$name' as a parameter name.");
        }
        // Store the parameter name and its passed value.
        $this->parameters[$name] = $value;
    }

    /**
     * Checks if a parameter with the given name exists in this frame.
     * Used internally by `defineOrUpdateVariable` to prevent assignment to parameters.
     * @param string $name The parameter name to check.
     * @return bool True if the parameter exists, false otherwise.
     */
    public function hasParameter(string $name): bool
    {
        return array_key_exists($name, $this->parameters);
    }

    /**
     * Defines a new local variable or updates an existing one within this frame.
     * Called by the Interpreter when executing an assignment statement (var := expr).
     * Prevents assignment to parameters (which are read-only) and assignment to
     * reserved keywords.
     *
     * @param string $name The name of the local variable.
     * @param BaseValue $value The value to assign to the variable.
     * @throws ParameterCollisionException If attempting to assign to a parameter or a reserved keyword.
     */
    public function defineOrUpdateVariable(string $name, BaseValue $value): void
    {
        // Check if the name corresponds to an existing parameter in this frame.
        if ($this->hasParameter($name)) {
            // Throw error 34: Cannot assign to parameters.
            throw new ParameterCollisionException("Attempt to assign to parameter '$name'. Parameters are read-only.");
        }
        // Prevent assigning values to reserved keywords.
        if ($name === 'self' || $name === 'super' || $name === 'nil' || $name === 'true' || $name === 'false') {
            // Throw error 34: Cannot use reserved keywords as assignable variables.
            throw new ParameterCollisionException("Cannot use reserved keyword '$name' as " .
                "a variable name for assignment.");
        }

        // Store or update the variable name and its value.
        $this->variables[$name] = $value;
    }

    /**
     * Retrieves the value associated with a given name from this frame.
     * Handles lookup order:
     * 1. Check for special keywords (nil, true, false, self, super).
     * 2. Check for parameters defined in this frame.
     * 3. Check for local variables defined in this frame.
     * Throws exceptions for accessing 'super' as a value, using 'self' incorrectly,
     * or accessing an undefined name.
     *
     * @param string $name The name of the variable, parameter, or keyword to retrieve.
     * @return BaseValue The corresponding SOL25 value object.
     * @throws TypeException If 'super' is accessed as a value or 'self' is used outside a method context.
     * @throws UndefinedVariableException If the name is not found as a keyword, parameter, or variable.
     */
    public function getVariableOrParameter(string $name): BaseValue
    {
        // Handle special keywords first.

        // 'super' is only valid as a receiver in a message send, not as a value itself.
        if ($name === 'super') {
            throw new TypeException("'super' cannot be used as a value, only as a message receiver.");
        }

        // Return singleton instances for built-in constants.
        if ($name === 'nil') {
            return NilValue::getInstance();
        }
        if ($name === 'true') {
            return TrueValue::getInstance();
        }
        if ($name === 'false') {
            return FalseValue::getInstance();
        }

        // Return the 'self' object associated with this frame, if it exists.
        if ($name === 'self') {
            if ($this->selfContext !== null) {
                return $this->selfContext;
            } else {
                // Cannot use 'self' if the frame wasn't created with an object context.
                throw new TypeException("Cannot use 'self' outside of a method context.");
            }
        }

        // If not a keyword, check if it's a parameter defined in this frame.
        if (array_key_exists($name, $this->parameters)) {
            /** @var BaseValue $parameterValue PHPStan hint */
            $parameterValue = $this->parameters[$name];
            return $parameterValue;
        }

        // If not a parameter, check if it's a local variable defined in this frame.
        if (array_key_exists($name, $this->variables)) {
            /** @var BaseValue $variableValue PHPStan hint */
            $variableValue = $this->variables[$name];
            return $variableValue;
        }

        // If the name was not found as a keyword, parameter, or variable, it's undefined.
        // Throw error 32 (according to spec, although UndefinedVariableException uses PARSE_UNDEF_ERROR code).
        throw new UndefinedVariableException("Undefined variable, parameter or keyword '$name' accessed.");
    }


    /**
     * Returns the 'self' object context associated with this frame.
     * Used by the Interpreter when evaluating block literals to capture the correct 'self'.
     * @return BaseValue|null The 'self' object, or null if none.
     */
    public function getSelf(): ?BaseValue
    {
        return $this->selfContext;
    }
}
