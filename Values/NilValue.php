<?php

declare(strict_types=1);

namespace IPP\Student\Values;

use Exception;
use IPP\Student\Exception\DoNotUnderstandException;
use IPP\Student\Exception\TypeException;

final class NilValue extends BaseValue
{
    // Singleton instance of NilValue
    private static ?NilValue $instance = null;

    /**
     * Stores instance attributes dynamically added to the nil object.
     * NOTE: This makes the singleton mutable, which is unusual but required by tests.
     * @var array<string, BaseValue>
     */
    private array $attributes = [];

    // Private constructor to prevent instantiation
    private function __construct()
    {
        parent::__construct('Nil');
    }

    // Prevent cloning of the singleton instance
    private function __clone()
    {
    }

    // Prevent serialization of the singleton instance
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton NilValue");
    }

    // Method to get the singleton instance of NilValue
    public static function getInstance(): NilValue
    {
        // Check if the instance is null, if so create a new instance
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Method to check if the value is nil
    public function methodAsString(): BaseValue
    {
        return StringValue::fromString('nil');
    }

    // Method to check if the value is nil
    public function methodIsNil(): BaseValue
    {
        return TrueValue::getInstance();
    }

    /**
     * Handles message sending for the Nil singleton.
     * Overrides BaseValue to add support for dynamic attributes,
     * required to pass specific tests, even if unusual for a singleton.
     * Prioritizes built-in methods over dynamic attribute access.
     *
     * @param string $selector The message selector.
     * @param array<int, BaseValue> $arguments The arguments.
     * @return BaseValue The result.
     * @throws DoNotUnderstandException If the message is not understood.
     * @throws TypeException If attribute access conflicts with built-in methods.
     */
    public function sendMessage(string $selector, array $arguments): BaseValue
    {
        $argc = count($arguments);

        // 1. Check for known built-in/inherited methods FIRST
        $knownMethods = [
            'asString' => 0,
            'isNil' => 0,
            'identicalTo:' => 1,
            'equalTo:' => 1,
            'isNumber' => 0,
            'isString' => 0,
            'isBlock' => 0,
        ];

        if (isset($knownMethods[$selector])) {
            // If it's a known method, check arity and delegate to parent::sendMessage
            if ($knownMethods[$selector] === $argc) {
                // Let BaseValue handle the method call
                return parent::sendMessage($selector, $arguments);
            } else {
                // Arity mismatch for a known method
                throw new DoNotUnderstandException(
                    "Arity mismatch for built-in method '{$selector}' on Nil:" .
                    "Expected {$knownMethods[$selector]} arguments, got {$argc}."
                );
            }
        }

        // 2. If not a known built-in method, check for Dynamic Attribute Setter
        if (str_ends_with($selector, ':') && $argc === 1) {
            $attributeName = rtrim($selector, ':');
            // Set attribute and return self (nil)
            $this->attributes[$attributeName] = $arguments[0];
            return $this; // Return the singleton instance itself
        }

        // 3. If not a built-in method or setter, check for Dynamic Attribute Getter
        if (!str_contains($selector, ':') && $argc === 0) {
            $attributeName = $selector;
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

        // 4. If none of the above matched (including arity mismatch for potential attributes)
        throw new DoNotUnderstandException(
            "Object<{$this->getSolClassName()}> does not understand message '{$selector}'" .
            " (unhandled pattern or arity)."
        );
    }

    /**
     * Gets all attributes of this object instance.
     * Required for potential 'from:' operations copying from Nil (though unlikely).
     * @return array<string, BaseValue> Map of attribute names to their values.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }
}
