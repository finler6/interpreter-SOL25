<?php

declare(strict_types=1);

namespace IPP\Student\Values;

// Import necessary classes and interfaces.
use IPP\Student\Values\BaseValue;
use IPP\Student\Values\TrueValue;
use IPP\Student\Values\FalseValue;
use IPP\Student\Values\NilValue;
use IPP\Student\Values\IntegerValue;
use IPP\Student\Exception\ValueException;
use IPP\Student\Exception\TypeException;

/**
 * Represents a String value in the SOL25 language.
 * It wraps a native PHP string.
 */
class StringValue extends BaseValue
{
    /**
     * The actual PHP string value stored internally.
     * Escape sequences from SOL25 literals should be resolved before storing here.
     * @var string
     */
    private string $value;

    /**
     * Constructor for StringValue.
     * @param string $phpStringValue The native PHP string to wrap.
     */
    public function __construct(string $phpStringValue)
    {
        parent::__construct('String'); // Set the SOL25 class name.
        $this->value = $phpStringValue;
    }

    /**
     * Static factory method for creating StringValue instances.
     * Useful for cleaner code where a new StringValue is needed.
     * @param string $phpStringValue The native PHP string.
     * @return StringValue A new instance wrapping the string.
     */
    public static function fromString(string $phpStringValue): StringValue
    {
        return new self($phpStringValue);
    }

    /**
     * Gets the internal PHP string value.
     * @return string The raw PHP string.
     */
    public function getInternalValue(): string
    {
        return $this->value;
    }

    /**
     * Handles the 'asString' message.
     * For a StringValue, this simply returns the object itself.
     * @return BaseValue Returns this StringValue instance.
     */
    public function methodAsString(): BaseValue
    {
        return $this; // A string converted to a string is itself.
    }

    /**
     * Handles the 'equalTo:' message.
     * Compares the internal string value with another value.
     * It also handles comparison with ObjectValues that might internally represent a String
     * (e.g., instances of a user-defined class inheriting String).
     * @param BaseValue $other The value to compare against.
     * @return BaseValue TrueValue if the strings are equal, FalseValue otherwise.
     */
    public function methodEqualTo(BaseValue $other): BaseValue
    {
        $otherStringValue = null; // Variable to hold the string value of the $other object, if applicable.

        // If the other object is also a StringValue, get its internal string directly.
        if ($other instanceof StringValue) {
            $otherStringValue = $other->getInternalValue();
            // If the other object is an ObjectValue, check if it has an internal StringValue.
            // This supports comparing StringValue with instances of String subclasses.
        } elseif ($other instanceof ObjectValue) {
            $otherAttributes = $other->getAttributes();
            if (
                isset($otherAttributes['__internal_value']) && // Check if internal value exists
                $otherAttributes['__internal_value'] instanceof StringValue // Check if it's a StringValue
            ) {
                // Get the string from the internal value.
                $otherStringValue = $otherAttributes['__internal_value']->getInternalValue();
            }
        }

        // If we successfully extracted a string value from $other, compare it.
        if ($otherStringValue !== null) {
            return ($this->value === $otherStringValue) ? TrueValue::getInstance() : FalseValue::getInstance();
        }

        // If $other was not a StringValue or a compatible ObjectValue, they are not equal.
        return FalseValue::getInstance();
    }

    /**
     * Handles the 'isString' message.
     * Always returns TrueValue for StringValue instances.
     * @return BaseValue The singleton TrueValue instance.
     */
    public function methodIsString(): BaseValue
    {
        return TrueValue::getInstance();
    }

    /**
     * Handles the 'print' message.
     * According to the specification, 'print' returns 'self'.
     * The actual printing to output is handled by the Interpreter
     * when it catches this specific message send.
     * @return BaseValue Returns this StringValue instance.
     */
    public function methodPrint(): BaseValue
    {
        // The Interpreter class intercepts 'print' calls on StringValue
        // to perform the actual output. This method just fulfills the
        // requirement that 'print' returns self.
        return $this;
    }

    /**
     * Handles the 'asInteger' message.
     * Attempts to convert the string value to an integer.
     * Returns NilValue if the conversion is not possible according to standard PHP rules.
     * @return BaseValue An IntegerValue if conversion succeeds, otherwise NilValue.
     */
    public function methodAsInteger(): BaseValue
    {
        // Use PHP's built-in filter to validate and convert the string to an integer.
        $intValue = filter_var($this->value, FILTER_VALIDATE_INT);

        // filter_var returns false if the string is not a valid integer representation.
        if ($intValue === false) {
            return NilValue::getInstance(); // Return Nil as per SOL25 spec.
        } else {
            // Conversion successful, return a new IntegerValue.
            return IntegerValue::fromInt($intValue);
        }
    }

    /**
     * Handles the 'concatenateWith:' message.
     * Appends the string value of another StringValue object to this one.
     * Returns NilValue if the argument is not a StringValue.
     * @param BaseValue $other The StringValue object whose content should be appended.
     * @return BaseValue A new StringValue with the concatenated result, or NilValue on type mismatch.
     */
    public function methodConcatenateWith(BaseValue $other): BaseValue
    {
        // Check if the argument is a StringValue.
        if ($other instanceof StringValue) {
            // Perform standard PHP string concatenation.
            $newValue = $this->value . $other->getInternalValue();
            // Return a new StringValue instance with the result.
            return self::fromString($newValue);
        } else {
            // Argument was not a StringValue, return Nil as per SOL25 spec.
            return NilValue::getInstance();
        }
    }

    /**
     * Handles the 'startsWith:endsBefore:' message.
     * Extracts a substring based on 1-based start and end indices.
     * Returns NilValue if arguments are not positive integers.
     * Handles UTF-8 characters correctly.
     * @param BaseValue $start The starting index (1-based). Must be IntegerValue.
     * @param BaseValue $end The index before which the substring ends (1-based). Must be IntegerValue.
     * @return BaseValue A new StringValue containing the substring, an empty StringValue, or NilValue on error.
     */
    public function methodStartsWithEndsBefore(BaseValue $start, BaseValue $end): BaseValue
    {
        // Validate argument types: both must be Integer instances.
        if (!$start instanceof IntegerValue || !$end instanceof IntegerValue) {
            return NilValue::getInstance(); // Type error, return Nil.
        }

        // Get the integer values from the arguments.
        $startInt = $start->getInternalValue(); // SOL25 uses 1-based indexing.
        $endInt = $end->getInternalValue();     // This is the index *after* the last character included.

        // Validate index values: must be positive integers.
        if ($startInt <= 0 || $endInt <= 0) {
            return NilValue::getInstance(); // Index error, return Nil.
        }

        // Calculate the desired length of the substring.
        $length = $endInt - $startInt;

        // If length is zero or negative, return an empty string as per SOL25 spec.
        if ($length <= 0) {
            return self::fromString('');
        }

        // Convert the 1-based start index to a 0-based index for PHP's mb_substr.
        $zeroBasedStart = $startInt - 1;

        // Extract the substring using mb_substr for UTF-8 compatibility.
        // mb_substr(string, start_index, length, encoding)
        $substring = mb_substr($this->value, $zeroBasedStart, $length, 'UTF-8');

        // Return the extracted substring wrapped in a new StringValue.
        return self::fromString($substring);
    }
}
