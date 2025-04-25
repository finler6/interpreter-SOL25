<?php

declare(strict_types=1);

namespace IPP\Student\Runtime;

// Import necessary classes used within this file.
use IPP\Student\Values\BlockValue;     // Type hint for methods.
use IPP\Student\Exception\TypeException;

// For errors like redefining parents/methods.

/**
 * Represents the definition of a single SOL25 class (user-defined or built-in).
 * It stores the class name, its parent class, and the methods defined directly within it.
 * This class is managed by the ClassManager.
 */
class UserDefinedClass
{
    /**
     * The name of the SOL25 class (e.g., "Main", "MyClass", "Integer").
     * @var string
     */
    private string $name;

    /**
     * A reference to the UserDefinedClass object representing the parent class.
     * Null if this class has no parent (should only be true for the ultimate base class, likely Object).
     * @var UserDefinedClass|null
     */
    private ?UserDefinedClass $parent = null;

    /**
     * An associative array storing the methods defined directly in this class.
     * Keys are the SOL25 method selectors (strings, e.g., "run", "plus:", "ifTrue:ifFalse:").
     * Values are BlockValue objects representing the method bodies.
     * This array does *not* include inherited methods.
     * @var array<string, BlockValue>
     */
    private array $methods = [];

    /**
     * Constructor for the class definition.
     * @param string $name The name of the SOL25 class.
     */
    public function __construct(string $name)
    {
        $this->name = $name;
        // Parent and methods are added later during parsing.
    }

    /**
     * Gets the name of this SOL25 class.
     * @return string The class name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets the parent class for this class definition.
     * Ensures that the parent can only be set once.
     *
     * @param UserDefinedClass $parent The UserDefinedClass object representing the parent.
     * @throws TypeException If attempting to set the parent when one is already set.
     */
    public function setParent(UserDefinedClass $parent): void
    {
        // Prevent changing the parent class after it has been set initially.
        if ($this->parent !== null) {
            throw new TypeException("Cannot redefine parent for class '$this->name'");
        }
        $this->parent = $parent;
    }

    /**
     * Gets the parent class definition object.
     * @return UserDefinedClass|null The parent class definition, or null if no parent is set.
     */
    public function getParent(): ?UserDefinedClass
    {
        return $this->parent;
    }

    /**
     * Adds a method definition (selector and body block) directly to this class.
     * Checks for duplicate method definitions within the same class.
     *
     * @param string $selector The SOL25 method selector (e.g., "myMethod:").
     * @param BlockValue $blockValue The BlockValue object representing the method's code.
     * @throws TypeException If a method with the same selector is already defined in *this specific class*.
     */
    public function addMethod(string $selector, BlockValue $blockValue): void
    {
        // Prevent defining the same method selector twice within the same class.
        // Note: This does not prevent overriding an inherited method.
        if (isset($this->methods[$selector])) {
            throw new TypeException("Method '$selector' is already defined in class '$this->name'.");
        }
        // Store the method body (BlockValue) associated with its selector.
        $this->methods[$selector] = $blockValue;
    }

    /**
     * Gets the method definition (BlockValue) for a given selector *only* if it's
     * defined directly within this class. It does *not* check parent classes.
     *
     * @param string $selector The SOL25 method selector.
     * @return BlockValue|null The BlockValue if the method is defined here, otherwise null.
     */
    public function getMethod(string $selector): ?BlockValue
    {
        // Look up the selector in the methods array of this specific class.
        // The null coalescing operator ?? handles the case where the key doesn't exist.
        /** @var BlockValue|null $method */
        $method = $this->methods[$selector] ?? null;
        return $method;
    }

    /**
     * Finds a method definition (BlockValue) for a given selector, searching
     * first in this class and then recursively up the inheritance chain (parents).
     * This implements the standard method lookup mechanism.
     *
     * @param string $selector The SOL25 method selector to find.
     * @return BlockValue|null The BlockValue if the method is found in this class or any ancestor, otherwise null.
     */
    public function findMethod(string $selector): ?BlockValue
    {
        // First, check if the method is defined directly in this class.
        $method = $this->getMethod($selector);
        if ($method !== null) {
            return $method; // Found it here.
        }

        // If not found here, and if this class has a parent, ask the parent to find it.
        // This creates a recursive lookup up the inheritance chain.
        if ($this->parent !== null) {
            return $this->parent->findMethod($selector);
        }

        // If not found here and there is no parent (or the parent search returned null),
        // then the method is not found in the hierarchy starting from this class.
        return null;
    }

    /**
     * Gets the map of methods defined *directly* in this class.
     * Useful for introspection or debugging. Does not include inherited methods.
     * @return array<string, BlockValue> Associative array of selector => BlockValue.
     */
    public function getDefinedMethods(): array
    {
        return $this->methods;
    }

    /**
     * Finds a method definition (BlockValue) for a given selector, searching
     * *only* in the parent class and its ancestors (used for 'super' calls).
     * It explicitly skips checking the current class.
     *
     * @param string $selector The SOL25 method selector to find in the parent chain.
     * @return BlockValue|null The BlockValue if found in an ancestor, otherwise null.
     */
    public function findMethodInParent(string $selector): ?BlockValue
    {
        // If this class has a parent, delegate the search entirely to the parent.
        // The parent's findMethod will search itself and its own parents recursively.
        if ($this->parent !== null) {
            return $this->parent->findMethod($selector);
        }
        // If there is no parent, the method cannot be found in the parent chain.
        return null;
    }
}
