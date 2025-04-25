<?php

declare(strict_types=1);

namespace IPP\Student\Values;

// Import necessary classes and exceptions.
use IPP\Student\Exception\DoNotUnderstandException;
use IPP\Student\Runtime\UserDefinedClass;
use IPP\Student\Values\TrueValue;    // For boolean results
use IPP\Student\Values\FalseValue;   // For boolean results
use IPP\Student\Values\StringValue;

/**
 * Abstract base class for all SOL25 value types (Integer, String, Block, Object, etc.).
 * It provides:
 * - A common constructor to set the SOL25 class name.
 * - A default implementation for message sending (`sendMessage`) which maps SOL25 selectors
 * to PHP method names (e.g., 'plus:' becomes 'methodPlus').
 * - Default implementations for methods inherited from the SOL25 'Object' class
 * (like identicalTo:, equalTo:, asString, isNil, etc.).
 * Subclasses should override these methods where necessary.
 */
abstract class BaseValue
{
    /**
     * The name of the corresponding SOL25 class (e.g., "Integer", "String", "MyClass").
     * Set by the constructor.
     * @var string
     */
    protected string $solClassName;

    /**
     * Reference to the UserDefinedClass object, if this value represents
     * an instance of a user-defined class (specifically for ObjectValue).
     * Null for built-in types like Integer, String etc.
     * @var UserDefinedClass|null
     */
    protected ?UserDefinedClass $actualSolClassInfo = null;

    /**
     * Gets the name of the SOL25 class for this value.
     * Uses the actual class info if available (for ObjectValue),
     * otherwise uses the name set in the constructor.
     * @return string The SOL25 class name.
     */
    public function getSolClassName(): string
    {
        // If this object knows its detailed class info (ObjectValue), use its name.
        // Otherwise, use the basic name given at construction (e.g., "Integer").
        return $this->actualSolClassInfo?->getName() ?? $this->solClassName;
    }

    /**
     * Base constructor for all value types.
     * @param string $solClassName The name of the SOL25 class this value represents.
     */
    public function __construct(string $solClassName)
    {
        $this->solClassName = $solClassName;
    }

    /**
     * Default message sending mechanism.
     * Converts the SOL25 selector into a corresponding PHP method name
     * (e.g., 'plus:' -> 'methodPlus', 'doSomething' -> 'methodDoSomething', 'ifTrue:ifFalse:' -> 'methodIfTrueIfFalse')
     * and calls it on the current object if it exists.
     *
     * Subclasses (like ObjectValue) override this to add logic for user-defined methods,
     * attribute access, or delegation before falling back to this base implementation.
     *
     * @param string $selector The SOL25 message selector.
     * @param array<int, BaseValue> $arguments The arguments passed with the message.
     * @return BaseValue The result of the message send (method call).
     * @throws \IPP\Student\Exception\DoNotUnderstandException If no corresponding PHP method exists
     * or DNU determined by subclass.
     * @throws \IPP\Student\Exception\MethodRequiresExecutionException Can be thrown by subclasses like ObjectValue.
     * @throws \IPP\Student\Exception\ValueException Can be thrown by specific method implementations.
     * @throws \IPP\Student\Exception\TypeException Can be thrown by specific method implementations or ObjectValue.
     * @throws \InvalidArgumentException Should not happen with type hints, but technically possible.
     */
    public function sendMessage(string $selector, array $arguments): BaseValue
    {
        // Convert the SOL25 selector to the expected PHP method name format.
        $methodName = $this->selectorToPhpMethodName($selector);

        // Check if a method with that name exists in the current class (or its PHP parents).
        if (method_exists($this, $methodName)) {
            /**
             * Call the found PHP method, passing the SOL25 arguments.
             * The spread operator (...) unpacks the $arguments array into individual arguments for the method call.
             * PHPStan might warn about argument types, but methods should have correct type hints.
             * @var BaseValue $result Assumes all 'method...' functions return a BaseValue.
             */
            $result = $this->$methodName(...$arguments);
            return $result;
        } else {
            // If no corresponding PHP method is found, the object doesn't understand the message.
            throw new DoNotUnderstandException("Object<{$this->getSolClassName()}> " .
                "does not understand message '$selector'");
        }
    }

    /**
     * Converts a SOL25 message selector string into a corresponding PHP method name.
     * Convention: 'method' + selector parts joined with CamelCase.
     * Examples:
     * 'asString'      -> 'methodAsString'
     * 'plus:'         -> 'methodPlus'
     * 'equalTo:'      -> 'methodEqualTo'
     * 'ifTrue:ifFalse:' -> 'methodIfTrueIfFalse'
     * 'value:value:'  -> 'methodValueValue'
     *
     * @param string $selector The SOL25 selector.
     * @return string The calculated PHP method name.
     */
    protected function selectorToPhpMethodName(string $selector): string
    {
        $phpMethodName = 'method'; // All mapped methods start with 'method'.
        // Split the selector by the colon ':'. Parts correspond to keywords between colons.
        $parts = explode(':', $selector);

        if ($parts[0] === '') {
            return $phpMethodName; // Should not happen with valid selectors.
        }

        // Append the first part (before the first colon, or the whole selector if no colon).
        $phpMethodName .= $parts[0];

        // Append subsequent parts (after colons), capitalizing the first letter of each part.
        for ($i = 1; $i < count($parts); $i++) {
            $part = $parts[$i];
            // Ignore empty parts (e.g., from a selector ending in ':').
            if ($part !== '') {
                $phpMethodName .= ucfirst($part); // Capitalize first letter for CamelCase.
            }
        }

        return $phpMethodName;
    }

    // --- Default implementations for methods inherited from SOL25 Object ---

    /**
     * Handles the 'identicalTo:' message.
     * Checks if the other object is the exact same instance in memory (PHP identity ===).
     * @param BaseValue $other The object to compare with.
     * @return BaseValue TrueValue if identical, FalseValue otherwise.
     */
    public function methodIdenticalTo(BaseValue $other): BaseValue
    {
        // Use PHP's strict object comparison (identity).
        return ($this === $other) ? TrueValue::getInstance() : FalseValue::getInstance();
    }

    /**
     * Handles the 'equalTo:' message.
     * Default implementation compares identity (like identicalTo:).
     * Subclasses (like Integer, String, maybe ObjectValue for deep comparison)
     * MUST override this to provide meaningful value comparison.
     * @param BaseValue $other The object to compare with.
     * @return BaseValue TrueValue if identical by default, FalseValue otherwise.
     */
    public function methodEqualTo(BaseValue $other): BaseValue
    {
        // Default behavior is identity comparison. Subclasses override this for value comparison.
        return $this->methodIdenticalTo($other);
    }

    /**
     * Handles the 'asString' message.
     * Default implementation returns an empty string.
     * Subclasses (Integer, String, Nil, True, False, etc.) MUST override this
     * to provide a meaningful string representation.
     * @return BaseValue A StringValue containing an empty string by default.
     */
    public function methodAsString(): BaseValue
    {
        // Default string representation is empty. Subclasses provide actual conversions.
        return StringValue::fromString('');
    }

    /**
     * Handles the 'isNumber' message.
     * Default implementation returns FalseValue.
     * Only IntegerValue (and its potential subclasses via delegation) should override this to return TrueValue.
     * @return BaseValue The singleton FalseValue instance by default.
     */
    public function methodIsNumber(): BaseValue
    {
        return FalseValue::getInstance();
    }

    /**
     * Handles the 'isString' message.
     * Default implementation returns FalseValue.
     * Only StringValue (and its potential subclasses via delegation) should override this to return TrueValue.
     * @return BaseValue The singleton FalseValue instance by default.
     */
    public function methodIsString(): BaseValue
    {
        return FalseValue::getInstance();
    }

    /**
     * Handles the 'isBlock' message.
     * Default implementation returns FalseValue.
     * Only BlockValue (and its potential subclasses via delegation) should override this to return TrueValue.
     * @return BaseValue The singleton FalseValue instance by default.
     */
    public function methodIsBlock(): BaseValue
    {
        return FalseValue::getInstance();
    }

    /**
     * Handles the 'isNil' message.
     * Default implementation returns FalseValue.
     * Only NilValue should override this to return TrueValue.
     * @return BaseValue The singleton FalseValue instance by default.
     */
    public function methodIsNil(): BaseValue
    {
        return FalseValue::getInstance();
    }

    /**
     * PHP magic method for string conversion.
     * Used internally by PHP when an object is treated as a string (e.g., in echo, string concatenation).
     * Tries to call the SOL25 'asString' method for a proper representation.
     * Provides a fallback representation if 'asString' fails or doesn't return a StringValue.
     * @return string The string representation of the object.
     */
    public function __toString(): string
    {
        try {
            // Attempt to get the official SOL25 string representation.
            $solString = $this->methodAsString();
            // Check if the result is actually a StringValue we can use.
            if ($solString instanceof StringValue) {
                return $solString->getInternalValue();
            }
        } catch (\Throwable $e) {
            // Ignore errors during __toString conversion (PHP requirement).
            // For example, if methodAsString itself throws an exception.
        }
        // Fallback representation if asString failed or returned a non-string.
        // Useful for debugging PHP errors.
        return "[PHP: " . static::class . "(" . $this->getSolClassName() . ")#" . spl_object_id($this) . "]";
    }
}
