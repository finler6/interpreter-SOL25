<?php

declare(strict_types=1);

namespace IPP\Student\Values;

use Exception;
use IPP\Student\Exception\DoNotUnderstandException;
use IPP\Core\Exception\InternalErrorException;
use IPP\Student\Exception\TypeException;

final class FalseValue extends BaseValue
{
    /**
     * The singleton instance of FalseValue.
     * @var FalseValue|null
     */
    private static ?FalseValue $instance = null;

    /**
     * Stores instance attributes dynamically added to the false object.
     * NOTE: This makes the singleton mutable, which is unusual but required by tests.
     * @var array<string, BaseValue>
     */
    private array $attributes = [];

    /**
     * The constructor is private to prevent direct instantiation.
     * Use the getInstance() method to get the singleton instance.
     */
    private function __construct()
    {
        parent::__construct('False');
    }

    private function __clone()
    {
    }

    /**
     * Prevents unserialization of the singleton instance.
     * @throws Exception
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton FalseValue");
    }

    /**
     * Returns the singleton instance of FalseValue.
     * @return FalseValue The singleton instance.
     */
    public static function getInstance(): FalseValue
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Returns the internal PHP boolean value.
     */
    public function methodAsString(): BaseValue
    {
        return StringValue::fromString('false');
    }

    /**
     * Handles the 'not' message for FalseValue.
     * @return BaseValue The singleton instance of TrueValue.
     */
    public function methodNot(): BaseValue
    {
        return TrueValue::getInstance();
    }

    /**
     * Handles message sending for the False singleton.
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
            'and:' => 1, // Interpreter handles short-circuit
            'or:' => 1,  // Interpreter handles short-circuit
            'ifTrue:ifFalse:' => 2, // Interpreter handles branching
            'identicalTo:' => 1,
            'equalTo:' => 1,
            'isNumber' => 0,
            'isString' => 0,
            'isBlock' => 0,
            'isNil' => 0,
        ];

        if (isset($knownMethods[$selector])) {
            // If it's a known method, check arity and delegate to parent::sendMessage
            // Exception: and:, or:, ifTrue:ifFalse: are handled specially by the Interpreter
            if ($knownMethods[$selector] === $argc) {
                // Let BaseValue handle the method call (will throw DNU for placeholders if called directly)
                return parent::sendMessage($selector, $arguments);
            } else {
                // Arity mismatch for a known method
                throw new DoNotUnderstandException(
                    "Arity mismatch for built-in method '{$selector}' on False:" .
                    "Expected {$knownMethods[$selector]} arguments, got {$argc}."
                );
            }
        }

        // 2. If not a known built-in method, check for Dynamic Attribute Setter
        if (str_ends_with($selector, ':') && $argc === 1) {
            $attributeName = rtrim($selector, ':');
            // Prevent conflict with known method names (basic check)
            if (isset($knownMethods[$attributeName . ':']) || isset($knownMethods[$attributeName])) {
                throw new TypeException("Attribute/Setter '$selector' conflicts with a built-in method name on False.");
            }
            // Set attribute and return self (false)
            $this->attributes[$attributeName] = $arguments[0];
            return $this; // Return the singleton instance itself
        }

        // 3. If not a built-in method or setter, check for Dynamic Attribute Getter
        if (!str_contains($selector, ':') && $argc === 0) {
            $attributeName = $selector;
            // Prevent conflict with known method names (basic check)
            if (isset($knownMethods[$attributeName . ':']) || isset($knownMethods[$attributeName])) {
                throw new TypeException("Attribute Getter '$attributeName' conflicts with a built-in"
                    . "method name on False.");
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
