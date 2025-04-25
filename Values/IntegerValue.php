<?php

declare(strict_types=1);

namespace IPP\Student\Values;

// Import necessary classes. Although True/False/String are used,
// they are resolved via autoloading, so explicit 'use' is optional but good practice.
use IPP\Student\Exception\ValueException;
use IPP\Student\Values\TrueValue;   // For comparisons
use IPP\Student\Values\FalseValue;  // For comparisons
use IPP\Student\Values\StringValue;

// For asString

/**
 * Represents an Integer value in the SOL25 language.
 * It wraps a native PHP integer.
 */
class IntegerValue extends BaseValue
{
    /**
     * The actual PHP integer value stored internally.
     * @var int
     */
    private int $value;

    /**
     * Constructor for IntegerValue.
     * @param int $phpIntValue The native PHP integer to wrap.
     */
    public function __construct(int $phpIntValue)
    {
        parent::__construct('Integer'); // Set the SOL25 class name.
        $this->value = $phpIntValue;
    }

    /**
     * Static factory method for creating IntegerValue instances.
     * Useful for cleaner code when a new IntegerValue is needed.
     * @param int $phpIntValue The native PHP integer.
     * @return IntegerValue A new instance wrapping the integer.
     */
    public static function fromInt(int $phpIntValue): IntegerValue
    {
        return new self($phpIntValue);
    }

    /**
     * Gets the internal PHP integer value.
     * @return int The raw PHP integer.
     */
    public function getInternalValue(): int
    {
        return $this->value;
    }

    /**
     * Private helper method to check if another BaseValue is an Integer
     * and return its internal PHP integer value.
     * Throws a ValueException if the type is incorrect. Used by arithmetic methods.
     *
     * @param BaseValue $other The operand to check.
     * @param string $operationName The name of the SOL25 operation (for error messages).
     * @return int The internal integer value of the $other operand.
     * @throws ValueException If $other is not an instance of IntegerValue.
     */
    private function checkIntegerOperand(BaseValue $other, string $operationName): int
    {
        // Check if the operand is actually an IntegerValue.
        // Note: This check doesn't automatically handle subclasses of Integer.
        // If subclasses need to be treated as Integers here, the check should be adjusted
        // or delegation via ObjectValue should handle unwrapping.
        if (!$other instanceof IntegerValue) {
            // Throw error 53 if the type is wrong for an arithmetic operation.
            throw new ValueException("Operand for $operationName must be an Integer, got {$other->getSolClassName()}");
        }
        // Return the internal PHP int value if the type is correct.
        return $other->getInternalValue();
    }

    /**
     * Handles the 'asString' message.
     * Converts the internal integer value to its string representation.
     * @return BaseValue A new StringValue containing the integer as a string.
     */
    public function methodAsString(): BaseValue
    {
        // Use PHP's built-in string conversion and wrap it in a StringValue.
        return StringValue::fromString(strval($this->value));
    }

    /**
     * Handles the 'equalTo:' message.
     * Compares the internal integer value with another value.
     * Only returns TrueValue if the other value is also an IntegerValue with the same internal value.
     * @param BaseValue $other The value to compare against.
     * @return BaseValue TrueValue if equal, FalseValue otherwise.
     */
    public function methodEqualTo(BaseValue $other): BaseValue
    {
        // Check if the other operand is an IntegerValue.
        if ($other instanceof IntegerValue) {
            // Perform strict comparison (value and type) on the internal PHP integers.
            return ($this->value === $other->getInternalValue()) ? TrueValue::getInstance() : FalseValue::getInstance();
        }
        // If the other operand is not an Integer, they cannot be equal in SOL25.
        return FalseValue::getInstance();
    }

    /**
     * Handles the 'isNumber' message.
     * Always returns TrueValue for IntegerValue instances.
     * @return BaseValue The singleton TrueValue instance.
     */
    public function methodIsNumber(): BaseValue
    {
        return TrueValue::getInstance();
    }

    /**
     * Handles the 'asInteger' message.
     * For an IntegerValue, this simply returns the object itself.
     * @return BaseValue Returns this IntegerValue instance.
     */
    public function methodAsInteger(): BaseValue
    {
        return $this; // An integer converted to an integer is itself.
    }

    /**
     * Handles the 'greaterThan:' message.
     * Compares this integer with another integer.
     * @param BaseValue $other The integer to compare against.
     * @return BaseValue TrueValue if this integer is greater, FalseValue otherwise.
     * @throws ValueException If $other is not an IntegerValue.
     */
    public function methodGreaterThan(BaseValue $other): BaseValue
    {
        // Check and get the internal value of the other operand.
        $otherValue = $this->checkIntegerOperand($other, 'greaterThan:');
        // Perform the comparison and return the appropriate boolean singleton.
        return ($this->value > $otherValue) ? TrueValue::getInstance() : FalseValue::getInstance();
    }

    /**
     * Handles the 'plus:' message (addition).
     * @param BaseValue $other The integer to add.
     * @return BaseValue A new IntegerValue containing the sum.
     * @throws ValueException If $other is not an IntegerValue.
     */
    public function methodPlus(BaseValue $other): BaseValue
    {
        $otherValue = $this->checkIntegerOperand($other, 'plus:');
        // Perform addition and return a new IntegerValue with the result.
        // Note: PHP integer overflow behavior applies here.
        return self::fromInt($this->value + $otherValue);
    }

    /**
     * Handles the 'minus:' message (subtraction).
     * @param BaseValue $other The integer to subtract.
     * @return BaseValue A new IntegerValue containing the difference.
     * @throws ValueException If $other is not an IntegerValue.
     */
    public function methodMinus(BaseValue $other): BaseValue
    {
        $otherValue = $this->checkIntegerOperand($other, 'minus:');
        // Perform subtraction and return a new IntegerValue.
        return self::fromInt($this->value - $otherValue);
    }

    /**
     * Handles the 'multiplyBy:' message (multiplication).
     * @param BaseValue $other The integer to multiply by.
     * @return BaseValue A new IntegerValue containing the product.
     * @throws ValueException If $other is not an IntegerValue.
     */
    public function methodMultiplyBy(BaseValue $other): BaseValue
    {
        $otherValue = $this->checkIntegerOperand($other, 'multiplyBy:');
        // Perform multiplication and return a new IntegerValue.
        return self::fromInt($this->value * $otherValue);
    }

    /**
     * Handles the 'divBy:' message (integer division).
     * Performs integer division compatible with the underlying PHP implementation.
     * @param BaseValue $other The integer to divide by (divisor).
     * @return BaseValue A new IntegerValue containing the integer quotient.
     * @throws ValueException If $other is not an IntegerValue, if division by zero occurs,
     * or if PHP's intdiv results in an error (like overflow).
     */
    public function methodDivBy(BaseValue $other): BaseValue
    {
        // Check and get the divisor's internal value.
        $divisor = $this->checkIntegerOperand($other, 'divBy:');

        // Explicitly check for division by zero, as required by SOL25 spec (Error 53).
        if ($divisor === 0) {
            throw new ValueException("Division by zero in divBy:");
        }

        // Use PHP's intdiv for integer division.
        // Catch potential PHP errors during division.
        try {
            $result = intdiv($this->value, $divisor);
        } catch (\DivisionByZeroError $e) {
            // This catch might be redundant due to the explicit check above, but it's safe.
            throw new ValueException("Division by zero in divBy:", $e);
        } catch (\ArithmeticError $e) {
            // Catch other arithmetic errors, like overflow (e.g., PHP_INT_MIN / -1).
            throw new ValueException("Integer division resulted in overflow (e.g., MIN_INT / -1)", $e);
        }

        // Return the integer result wrapped in a new IntegerValue.
        return self::fromInt($result);
    }
}
