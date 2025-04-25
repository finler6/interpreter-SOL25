<?php

declare(strict_types=1);

namespace IPP\Student\Values;

use IPP\Student\Exception\BlockRequiresExecutionException;
use IPP\Student\Exception\DoNotUnderstandException;
use IPP\Student\Exception\MethodRequiresExecutionException;
use IPP\Student\Exception\TypeException;
use IPP\Student\Runtime\UserDefinedClass;
use IPP\Student\Exception\ValueException;
// Include necessary Value types for type hints and internal logic
use IPP\Student\Values\BaseValue;
use IPP\Student\Values\BlockValue;
use IPP\Student\Values\IntegerValue;
use IPP\Student\Values\StringValue;
use Throwable;

// Catch potential errors during delegation/base calls

/**
 * Represents an instance of a user-defined SOL25 class (or the base Object class).
 * It holds instance attributes and handles message sending for these objects.
 */
class ObjectValue extends BaseValue
{
    /**
     * Stores the instance attributes (variables) of this object.
     * Keys are attribute names (strings), values are SOL25 value objects (BaseValue).
     * Includes a special '__internal_value' for delegation (e.g., for Integer subclasses).
     * @var array<string, BaseValue>
     */
    private array $attributes = [];

    /**
     * Information about the SOL25 class this object is an instance of.
     * @var UserDefinedClass
     */
    private UserDefinedClass $classInfo;

    /**
     * Constructor for ObjectValue.
     * @param UserDefinedClass $classInfo The definition of the class for this instance.
     */
    public function __construct(UserDefinedClass $classInfo)
    {
        // Initialize the parent (BaseValue) with the class name.
        parent::__construct($classInfo->getName());
        $this->classInfo = $classInfo;
        $this->actualSolClassInfo = $classInfo; // Link to the detailed class info
    }

    /**
     * Gets the UserDefinedClass object associated with this instance.
     * @return UserDefinedClass
     */
    public function getClassInfo(): UserDefinedClass
    {
        return $this->classInfo;
    }

    /**
     * Gets all attributes of this object instance.
     * Primarily used internally or for debugging/copying.
     * @return array<string, BaseValue> Map of attribute names to their values.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Handles sending a message (calling a method or accessing an attribute) to this object instance.
     * This method now acts as a dispatcher, calling private helper methods in the correct order.
     *
     * Order of checks:
     * 1. User-defined method in the class hierarchy.
     * 2. Specific 'value...' message if this object holds an internal BlockValue.
     * 3. Delegation to '__internal_value' for specific selectors.
     * 4. Built-in methods defined in BaseValue.
     * 5. Dynamic attribute setter/getter.
     *
     * @param string $selector The message selector (e.g., "myMethod", "attribute:", "plus:").
     * @param array<int, BaseValue> $arguments The arguments passed with the message.
     * @return BaseValue The result of the message send.
     * @throws \IPP\Student\Exception\MethodRequiresExecutionException If a user-defined method is found.
     * @throws \IPP\Student\Exception\BlockRequiresExecutionException
     * If a 'value...' message needs to execute an internal BlockValue.
     * @throws \IPP\Student\Exception\DoNotUnderstandException If the object cannot handle the message.
     * @throws \IPP\Student\Exception\TypeException If there's a conflict or type error during handling.
     * @throws \IPP\Student\Exception\ValueException If a value error occurs during handling (e.g., from delegation).
     */
    public function sendMessage(string $selector, array $arguments): BaseValue
    {
        /** @var ?BaseValue $result Stores the potential result from handlers */
        $result = null;
        /** @var ?DoNotUnderstandException Stores DNU from delegation attempt */
        $delegationDnu = null;
        /** @var ?DoNotUnderstandException Stores DNU from base method attempt */
        $baseValueDnu = null;

        // 1. Check for User-Defined Method
        // Throws MethodRequiresExecutionException directly if found
        $this->checkUserMethod($selector);

        // 2. Check for Internal BlockValue Execution ('value...')
        // Throws BlockRequiresExecutionException or DoNotUnderstandException if applicable
        $internalValueCheck = $this->attributes['__internal_value'] ?? null;
        // Check only if selector starts with 'value' and matches the pattern
        if (str_starts_with($selector, 'value') && preg_match('/^value(:*)$/', $selector, $matchesCheck)) {
            if ($internalValueCheck instanceof BlockValue) {
                $expectedArityCheck = $internalValueCheck->getArity();
                $selectorArityCheck = strlen($matchesCheck[1]); // Number of colons
                $actualArityCheck = count($arguments);

                if ($expectedArityCheck === $selectorArityCheck && $expectedArityCheck === $actualArityCheck) {
                    // Signal Interpreter to execute the stored block
                    throw new BlockRequiresExecutionException($internalValueCheck, $arguments);
                } else {
                    // Arity mismatch specific to executing the internal block via 'value...'
                    throw new DoNotUnderstandException(
                        "Arity mismatch for Block execution via ObjectValue: " .
                        "Selector '{$selector}' ({$selectorArityCheck} args), Block expects {$expectedArityCheck}, " .
                        "got {$actualArityCheck}."
                    );
                }
            }
            // If internalValue is not a BlockValue, this handler doesn't apply
        }

        // 3. Attempt Delegation
        $delegationOutcome = $this->attemptDelegation($selector, $arguments);
        if ($delegationOutcome['result'] !== null) {
            return $delegationOutcome['result']; // Delegation successful
        }
        $delegationDnu = $delegationOutcome['dnuException']; // Store potential DNU

        // 4. Attempt Base Class Method (identicalTo:, isNil etc.)
        $baseOutcome = $this->attemptBaseMethod($selector, $arguments);
        if ($baseOutcome['result'] !== null) {
            return $baseOutcome['result']; // Base method successful
        }
        $baseValueDnu = $baseOutcome['dnuException']; // Store potential DNU

        // 5. Attempt Attribute Access (Setter/Getter)
        $result = $this->checkAttributeAccess($selector, $arguments);
        if ($result !== null) {
            return $result; // Attribute access successful
        }

        // 6. If no handler returned a result, determine the final exception
        $finalException = null;
        // Prioritize DNU from the base method call attempt
        if ($baseValueDnu !== null) {
            $finalException = $baseValueDnu;
        } elseif ($delegationDnu !== null) {
            // Use DNU from delegation if base method didn't cause one
            $finalException = $delegationDnu;
        } else {
            // If neither specific DNU occurred, it means no handler could process the message.
            // This includes the fallthrough from attribute getter when attribute doesn't exist.
            $finalException = new DoNotUnderstandException(
                "Object<{$this->getSolClassName()}> does not understand message '{$selector}' " .
                "(checked user methods, internal block, delegation, base methods, attributes)."
            );
        }
        throw $finalException;
    }

    /**
     * Checks for a user-defined method in the class hierarchy.
     * Throws MethodRequiresExecutionException if found, allowing the Interpreter to handle execution.
     *
     * @param string $selector
     * @throws MethodRequiresExecutionException
     */
    private function checkUserMethod(string $selector): void
    {
        $methodBlock = $this->classInfo->findMethod($selector);
        if ($methodBlock !== null) {
            // Signal Interpreter to execute this method block
            throw new MethodRequiresExecutionException($methodBlock, null);
        }
    }

    /**
     * Attempts to delegate the message to the internal value (__internal_value).
     * Returns an array ['result' => BaseValue|null, 'dnuException' => DoNotUnderstandException|null].
     *
     * @param string $selector
     * @param array<int, BaseValue> $arguments
     * @return array{result: ?BaseValue, dnuException: ?DoNotUnderstandException}
     * @throws \IPP\Student\Exception\ValueException|\IPP\Student\Exception\TypeException
     * If delegation itself causes errors other than DNU.
     */
    private function attemptDelegation(string $selector, array $arguments): array
    {
        $internalValue = $this->attributes['__internal_value'] ?? null;
        $outcome = ['result' => null, 'dnuException' => null];

        $delegatableSelectors = [
            'equalTo:', 'greaterThan:', 'plus:', 'minus:', 'multiplyBy:', 'divBy:',
            'asString', 'asInteger', 'timesRepeat:',
            'concatenateWith:', 'startsWith:endsBefore:',
            'isNumber', 'isString', 'isBlock', 'isNil', 'print'
        ];

        if ($internalValue instanceof BaseValue && in_array($selector, $delegatableSelectors, true)) {
            try {
                $unwrappedArgs = [];
                foreach ($arguments as $arg) {
                    if (
                        $internalValue instanceof IntegerValue && $arg instanceof ObjectValue &&
                        isset($arg->attributes['__internal_value']) &&
                        $arg->attributes['__internal_value'] instanceof IntegerValue
                    ) {
                        $unwrappedArgs[] = $arg->attributes['__internal_value'];
                    } elseif (
                        $internalValue instanceof StringValue && $arg instanceof ObjectValue &&
                        isset($arg->attributes['__internal_value']) &&
                        $arg->attributes['__internal_value'] instanceof StringValue
                    ) {
                        $unwrappedArgs[] = $arg->attributes['__internal_value'];
                    } else {
                        $unwrappedArgs[] = $arg;
                    }
                }
                // Send the message to the internal value
                $outcome['result'] = $internalValue->sendMessage($selector, $unwrappedArgs);
            } catch (DoNotUnderstandException $e) {
                // Internal value didn't understand, store the exception
                $outcome['dnuException'] = $e;
            } catch (ValueException | TypeException $e) {
                // If delegation causes other runtime errors, re-throw them
                throw $e;
            } catch (Throwable $e) {
                // Catch unexpected errors during delegation
                throw new TypeException("Unexpected error during delegation of '$selector': " . $e->getMessage(), $e);
            }
        }
        return $outcome;
    }

    /**
     * Attempts to handle the message using methods from BaseValue (parent class).
     * Returns an array ['result' => BaseValue|null, 'dnuException' => DoNotUnderstandException|null].
     *
     * @param string $selector
     * @param array<int, BaseValue> $arguments
     * @return array{result: ?BaseValue, dnuException: ?DoNotUnderstandException}
     */
    private function attemptBaseMethod(string $selector, array $arguments): array
    {
        $outcome = ['result' => null, 'dnuException' => null];
        try {
            // Call the BaseValue's default sendMessage implementation
            $outcome['result'] = parent::sendMessage($selector, $arguments);
        } catch (DoNotUnderstandException $e) {
            // BaseValue didn't have the method, store the exception
            $outcome['dnuException'] = $e;
        }
        // Other exceptions from BaseValue methods would propagate automatically
        return $outcome;
    }

    /**
     * Checks for and handles dynamic attribute access (setters and getters).
     * Returns the result (self for setter, value for getter) or null if not handled.
     * Throws TypeException on conflicts or errors.
     *
     * @param string $selector
     * @param array<int, BaseValue> $arguments
     * @return ?BaseValue
     * @throws TypeException
     */
    private function checkAttributeAccess(string $selector, array $arguments): ?BaseValue
    {
        $argc = count($arguments);

        // 1. Handle Setter (selector: with 1 argument)
        if (str_ends_with($selector, ':') && $argc === 1) {
            $attributeName = rtrim($selector, ':');
            // Check for conflict with existing methods
            if (
                $this->classInfo->findMethod($attributeName) !== null || // Getter conflict
                $this->classInfo->findMethod($selector) !== null       // Setter conflict (redundant?)
            ) {
                throw new TypeException("Attribute/Setter '$selector' conflicts with an existing method.");
            }
            // Set attribute and return self
            $this->attributes[$attributeName] = $arguments[0];
            return $this;
        }

        // 2. Handle Getter (selector with 0 arguments)
        if (!str_contains($selector, ':') && $argc === 0) {
            $attributeName = $selector;
            // Check for conflict with existing methods
            if ($this->classInfo->findMethod($attributeName) !== null) {
                throw new TypeException("Attribute getter '$attributeName' conflicts with an existing method.");
            }
            // Check if attribute exists
            if (array_key_exists($attributeName, $this->attributes)) {
                /** @var BaseValue $attributeValue */
                $attributeValue = $this->attributes[$attributeName];
                return $attributeValue; // Return attribute value
            } else {
                // Attribute getter called, but attribute doesn't exist.
                // This handler doesn't apply, return null to let the main method throw DNU.
                return null;
            }
        }

        // Selector/argument combination doesn't match attribute access patterns
        return null;
    }
}
