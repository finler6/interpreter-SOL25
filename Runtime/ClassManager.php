<?php

declare(strict_types=1);

namespace IPP\Student\Runtime;

// Import necessary classes used within this file.
use IPP\Student\Exception\TypeException;     // For errors like duplicate class definitions.
use IPP\Student\Runtime\UserDefinedClass;

// The type of object managed by this class.

/**
 * Manages all defined SOL25 classes (both built-in and user-defined) during runtime.
 * It acts as a central registry for class definitions, allowing lookup by name
 * and ensuring classes are defined correctly.
 */
class ClassManager
{
    /**
     * Stores the class definitions.
     * Keys are the SOL25 class names (strings, e.g., "Integer", "Main").
     * Values are the corresponding UserDefinedClass objects containing class details.
     * @var array<string, UserDefinedClass>
     */
    private array $classes = [];

    /**
     * A constant list of the names of all built-in SOL25 classes.
     * Used during initialization.
     * @var array<int, string>
     */
    private const BUILTIN_CLASSES = [
        'Object', 'Nil', 'True', 'False', 'Integer', 'String', 'Block'
    ];

    /**
     * Constructor for the ClassManager.
     * Automatically creates UserDefinedClass objects for all built-in classes upon instantiation.
     * The inheritance hierarchy for built-ins is set later via `initializeBuiltInHierarchy`.
     */
    public function __construct()
    {
        // Create definition objects for all built-in classes.
        foreach (self::BUILTIN_CLASSES as $className) {
            $classDefinition = new UserDefinedClass($className);
            // Add the built-in class definition to the internal storage.
            $this->addClassInternal($classDefinition);
        }
        // Note: At this point, built-in classes (except Object) don't have their parent set yet.
    }

    /**
     * Sets up the inheritance relationships for the built-in classes.
     * Assumes all built-in classes inherit directly from 'Object'.
     * This method should be called after the constructor, typically once during interpreter setup.
     */
    public function initializeBuiltInHierarchy(): void
    {
        // Get the definition for the base 'Object' class.
        $objectClass = $this->getClass('Object'); // Assumes 'Object' was created in constructor.

        // Iterate through all built-in class names again.
        foreach (self::BUILTIN_CLASSES as $className) {
            // Skip 'Object' itself, as it has no parent in this model.
            if ($className === 'Object') {
                continue;
            }
            // Get the definition for the current built-in class.
            $childClass = $this->getClass($className);
            // Set its parent to the 'Object' class definition.
            $childClass->setParent($objectClass);
        }
    }

    /**
     * Adds a user-defined class definition to the manager.
     * Called by the Interpreter when parsing <class> tags from the XML.
     * Checks if a class with the same name (user-defined or built-in) already exists.
     *
     * @param UserDefinedClass $class The class definition object to add.
     * @throws TypeException If a class with the same name is already registered.
     */
    public function addClass(UserDefinedClass $class): void
    {
        $name = $class->getName();
        // Check for name collisions before adding.
        if (isset($this->classes[$name])) {
            // Error: Class name is already in use.
            throw new TypeException("Class '$name' already defined.");
        }
        // Add the class definition to the registry using its name as the key.
        $this->classes[$name] = $class;
    }

    /**
     * Internal helper method to add a class definition without checking for duplicates.
     * Used by the constructor to add built-in classes safely.
     *
     * @param UserDefinedClass $class The class definition object to add.
     */
    private function addClassInternal(UserDefinedClass $class): void
    {
        // Directly add/overwrite the class entry. Assumes no conflicts for internal use.
        $this->classes[$class->getName()] = $class;
    }


    /**
     * Retrieves a class definition object by its name.
     * Used throughout the interpreter to get information about classes (e.g., methods, parent).
     *
     * @param string $name The name of the SOL25 class to retrieve.
     * @return UserDefinedClass The found class definition object.
     * @throws TypeException If no class with the given name is defined (neither built-in nor user-defined).
     */
    public function getClass(string $name): UserDefinedClass
    {
        // Check if a class with this name exists in our registry.
        if (!isset($this->classes[$name])) {
            // Error: Tried to access a class that hasn't been defined.
            throw new TypeException("Attempted to access undefined class '$name'.");
        }
        // Type hint for PHPStan to understand the return type.
        /** @var UserDefinedClass $classDef */
        $classDef = $this->classes[$name];
        return $classDef;
    }

    /**
     * Checks if a class with the given name exists in the manager.
     * Useful for validation before attempting to get or use a class definition.
     *
     * @param string $name The name of the SOL25 class to check.
     * @return bool True if the class exists, false otherwise.
     */
    public function classExists(string $name): bool
    {
        // Simple check using isset() on the internal classes array.
        return isset($this->classes[$name]);
    }
}
