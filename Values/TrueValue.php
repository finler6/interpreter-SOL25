<?php

declare(strict_types=1);

namespace IPP\Student\Values;

use IPP\Student\Values\BaseValue;
use IPP\Student\Values\StringValue;
use IPP\Student\Values\FalseValue;
use IPP\Student\Exception\DoNotUnderstandException;
use IPP\Core\Exception\InternalErrorException;
use IPP\Student\Exception\TypeException;
use Exception;

final class TrueValue extends BaseValue
{
    /**
     * @var TrueValue|null
     */
    private static ?TrueValue $instance = null;

    /**
     * Stores instance attributes dynamically added to the true object.
     * NOTE: This makes the singleton mutable, which is unusual but required by tests.
     * @var array<string, BaseValue>
     */
    private array $attributes = [];

    /**
     * Private constructor to prevent instantiation.
     */
    private function __construct()
    {
        parent::__construct('True');
    }

    /**
     * Prevent cloning of the instance.
     */
    private function __clone()
    {
    }

    /**
     * Prevent serialization of the instance.
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton TrueValue");
    }

    /**
     * Returns the singleton instance of TrueValue.
     *
     * @return TrueValue
     */
    public static function getInstance(): TrueValue
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Returns the internal value of the TrueValue instance.
     *
     */
    public function methodAsString(): BaseValue
    {
        return StringValue::fromString('true');
    }

    public function methodNot(): BaseValue
    {
        return FalseValue::getInstance();
    }


    /**
     * Compares this TrueValue instance with another value.
     */
    public function methodAnd(BaseValue $blockArg): BaseValue
    {

        throw new InternalErrorException("TrueValue::method_and_ should not be called directly.");
    }

    public function methodOr(BaseValue $blockArg): BaseValue
    {
        return $this;
    }

    public function methodIfTrueIfFalse(BaseValue $trueBlock, BaseValue $falseBlock): BaseValue
    {
        throw new InternalErrorException("TrueValue::method_ifTrue_ifFalse_ should not be called directly.");
    }

    /**
     * Handles message sending for the True singleton.
     * Overrides BaseValue to add support for dynamic attributes.
     * Prioritizes built-in methods over dynamic attribute access.
     *
     * @param string $selector The message selector.
     * @param array<int, BaseValue> $arguments The arguments.
     * @return BaseValue The result.
     * @throws DoNotUnderstandException If the message is not understood.
     * @throws TypeException If attribute access conflicts with built-in methods or arity is wrong.
     */
    public function sendMessage(string $selector, array $arguments): BaseValue
    {
        $argc = count($arguments);

        // 1. Check for known built-in/inherited methods FIRST
        $knownMethods = [
            'asString' => 0,
            'not' => 0,
            'and:' => 1, // Placeholder, handled by Interpreter
            'or:' => 1,  // Placeholder, handled by Interpreter
            'ifTrue:ifFalse:' => 2, // Placeholder, handled by Interpreter
            'identicalTo:' => 1,
            'equalTo:' => 1,
            'isNumber' => 0,
            'isString' => 0,
            'isBlock' => 0,
            'isNil' => 0,
        ];

        if (isset($knownMethods[$selector])) {
            if ($knownMethods[$selector] === $argc) {
                return parent::sendMessage($selector, $arguments);
            } else {
                // Arity mismatch for a known method
                throw new DoNotUnderstandException(
                    "Arity mismatch for built-in method '{$selector}' on True:" .
                    "Expected {$knownMethods[$selector]} arguments, got {$argc}."
                );
            }
        }

        // 2. If not a known built-in method, check for Dynamic Attribute Setter
        if (str_ends_with($selector, ':') && $argc === 1) {
            $attributeName = rtrim($selector, ':');
            // Prevent conflict with known method names (basic check)
            if (isset($knownMethods[$attributeName . ':']) || isset($knownMethods[$attributeName])) {
                throw new TypeException("Attribute/Setter '$selector' conflicts with a built-in method name on True.");
            }
            // Set attribute and return self (true)
            $this->attributes[$attributeName] = $arguments[0];
            return $this; // Return the singleton instance itself
        }

        // 3. If not a built-in method or setter, check for Dynamic Attribute Getter
        if (!str_contains($selector, ':') && $argc === 0) {
            $attributeName = $selector;
            // Prevent conflict with known method names (basic check)
            if (isset($knownMethods[$attributeName . ':']) || isset($knownMethods[$attributeName])) {
                throw new TypeException("Attribute Getter '$attributeName' conflicts with a built-in"
                    . "method name on True.");
            }
            // Check if attribute exists
            if (array_key_exists($attributeName, $this->attributes)) {
                /** @var BaseValue $attributeValue PHPStan hint */
                $attributeValue = $this->attributes[$attributeName];
                return $attributeValue; // Return attribute value
            } else {
                // Attribute getter called, but attribute doesn't exist. Throw DNU.
                throw new DoNotUnderstandException(
                    "Object<{$this->getSolClassName()}> does not understand message '{$selector}'" .
                    " (attribute '{$attributeName}' not found)."
                );
            }
        }

        // 4. If none of the above matched
        throw new DoNotUnderstandException(
            "Object<{$this->getSolClassName()}> does not understand message '{$selector}'" .
            " (unhandled pattern or arity)."
        );
    }

    /**
     * Gets all attributes of this object instance.
     * @return array<string, BaseValue> Map of attribute names to their values.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }
}
