<?php

declare(strict_types=1);

namespace IPP\Student;

use DOMDocument;
use DOMElement;
use DOMXPath;
use IPP\Core\AbstractInterpreter;
use IPP\Core\ReturnCode;
use IPP\Core\Exception\InternalErrorException;
use IPP\Student\Exception\TypeException;
use IPP\Student\Runtime\ClassManager;
use IPP\Student\Runtime\UserDefinedClass;
use IPP\Student\Runtime\CallStack;
use IPP\Student\Values\BlockValue;
use IPP\Student\Values\ObjectValue;
use IPP\Student\Runtime\Frame;
use IPP\Student\Values\BaseValue;
use IPP\Student\Values\IntegerValue;
use IPP\Student\Values\StringValue;
use IPP\Student\Values\NilValue;
use IPP\Student\Values\TrueValue;
use IPP\Student\Values\FalseValue;
use IPP\Core\Exception\NotImplementedException;
use IPP\Student\Exception\DoNotUnderstandException;
use Throwable;
use IPP\Student\Exception\ValueException;
use IPP\Student\Exception\MethodRequiresExecutionException;
use IPP\Student\Exception\MissingMainRunException;
use IPP\Student\Exception\MethodArityException;

class Interpreter extends AbstractInterpreter
{
    /** @var DOMDocument|null The XML Abstract Syntax Tree (AST) loaded from the source file. */
    private ?DOMDocument $dom = null;
    /** @var DOMXPath|null Helper object to search within the XML document (DOM). */
    private ?DOMXPath $xpath = null;
    /** @var ClassManager|null Manages all defined classes (built-in and user-defined). */
    private ?ClassManager $classManager = null;
    /** @var CallStack|null Manages the stack of execution frames (for method calls and block execution). */
    private ?CallStack $callStack = null;
    /** @var string Special marker to identify when 'super' is used as a message receiver. */
    private const SUPER_MARKER = '##SUPER_CALL_MARKER##';

    /**
     * The main method that runs the interpretation process.
     * It coordinates loading, parsing, and execution.
     * @return int The exit code (0 for success, others for errors).
     */
    public function execute(): int
    {

        try {
            // 1. Load the XML source code and prepare it for processing.
            $this->loadAndParseXml();
            // 2. Set up the environment needed to run the code (classes, call stack).
            $this->initializeRuntime();
            // 3. Read class definitions from the XML.
            $this->parseClasses();

            // 4. Find the Main class and execute its 'run' method.
            $this->runMain();

            // If everything went well, return OK status.
            return ReturnCode::OK;
        } catch (DoNotUnderstandException | TypeException | ValueException $runtimeError) {
            // Catch specific SOL25 runtime errors and let the framework handle them.
            throw $runtimeError;
        } catch (NotImplementedException $nie) {
            // Handle cases where a feature is known to be missing.
            $this->stderr->writeString("INTERNAL ERROR: Feature not implemented: " . $nie->getMessage() . "\n");
            return ReturnCode::INTERNAL_ERROR;
        } catch (InternalErrorException | \IPP\Core\Exception\IPPException $internalOrCoreError) {
            // Catch internal errors or errors from the framework.
            throw $internalOrCoreError;
        } catch (Throwable $unknownError) { // Catch any other unexpected problems.
            $this->stderr->writeString("UNEXPECTED PHP ERROR: " . $unknownError->getMessage() . "\n");
            $this->stderr->writeString($unknownError->getTraceAsString() . "\n");
            return ReturnCode::INTERNAL_ERROR;
        }
    }

    /**
     * Evaluates a target that should understand 'value' (parameterless).
     * Handles BlockValue directly or calls value method on other objects.
     * @param BaseValue $target The object or block to evaluate.
     * @param string $contextForErrorMessage Description of where this is used (e.g., "'and:' argument").
     * @return BaseValue The result of the evaluation.
     * @throws TypeException|DoNotUnderstandException|MethodArityException
     * @throws MethodRequiresExecutionException Can be thrown by subclasses like ObjectValue.
     */
    private function evaluateZeroArityBlockOrMethod(BaseValue $target, string $contextForErrorMessage): BaseValue
    {
        // If the target is a BlockValue literal, execute it directly.
        if ($target instanceof BlockValue) {
            if ($target->getArity() !== 0) { // Ensure it takes zero arguments.
                throw new TypeException("Block argument for {$contextForErrorMessage} must be parameterless" .
                    "(understand 'value'), but has arity " . $target->getArity());
            }
            return $this->executeBlock($target, [], null); // Execute with no arguments.
        } else { // Otherwise, send the 'value' message to the object.
            try {
                return $target->sendMessage('value', []);
            } catch (DoNotUnderstandException $e) {
                // If it doesn't understand 'value', it's a type error in this context.
                throw new TypeException("Argument for {$contextForErrorMessage} must understand message 'value'", $e);
            } catch (MethodRequiresExecutionException $mree) {
                // If sendMessage found a user-defined 'value' method, execute its block.
                $methodBlock = $mree->blockValue;
                if ($methodBlock->getArity() !== 0) { // Check arity again for the method.
                    throw new MethodArityException("Runtime arity mismatch for 'value' method"
                        . "used in {$contextForErrorMessage}: Expected 0 arguments.");
                }
                // Execute method block in the context of the target.
                return $this->executeBlock($methodBlock, [], $target);
            }
        }
    }

    /**
     * Evaluates a target that should understand 'value:' (one parameter).
     * Handles BlockValue directly or calls value: method on other objects.
     * @param BaseValue $target The object or block to evaluate.
     * @param BaseValue $argument The argument to pass.
     * @param string $contextForErrorMessage Description of where this is used (e.g., "'timesRepeat:' body").
     * @return BaseValue The result of the evaluation.
     * @throws TypeException|DoNotUnderstandException|MethodArityException
     * @throws MethodRequiresExecutionException Is thrown directly when a user method is found.
     */
    private function evaluateOneArityBlockOrMethod(
        BaseValue $target,
        BaseValue $argument,
        string $contextForErrorMessage
    ): BaseValue {
        // If the target is a BlockValue literal, execute it directly.
        if ($target instanceof BlockValue) {
            if ($target->getArity() !== 1) { // Ensure it takes one argument.
                throw new TypeException("Block argument for {$contextForErrorMessage} must have one" .
                    "parameter (understand 'value:'), but has arity " . $target->getArity());
            }
            return $this->executeBlock($target, [$argument], null); // Execute with the provided argument.
        } else { // Otherwise, send the 'value:' message to the object.
            try {
                return $target->sendMessage('value:', [$argument]);
            } catch (DoNotUnderstandException $e) {
                // If it doesn't understand 'value:', it's a type error in this context.
                throw new TypeException("Argument for {$contextForErrorMessage} must understand message 'value:'", $e);
            } catch (MethodRequiresExecutionException $mree) {
                // If sendMessage found a user-defined 'value:' method, execute its block.
                $methodBlock = $mree->blockValue;
                if ($methodBlock->getArity() !== 1) { // Check arity again for the method.
                    throw new MethodArityException("Runtime arity mismatch for 'value:' method"
                        . "used in {$contextForErrorMessage}: Expected 1 argument.");
                }
                // Execute method block with the argument in the context of the target.
                return $this->executeBlock($methodBlock, [$argument], $target);
            }
        }
    }

    /**
     * Loads the XML from the source reader and performs basic validation.
     * Initializes $this->dom and $this->xpath.
     *
     * @throws TypeException If the XML is not well-formed or lacks the root <program language="SOL25"> structure.
     * @throws \IPP\Core\Exception\InputFileException If the source file cannot be read.
     * @throws \IPP\Core\Exception\XMLException If the XML is malformed.
     */
    private function loadAndParseXml(): void
    {
        // Get the DOM object from the source reader (provided by the framework).
        $this->dom = $this->source->getDOMDocument();
        // Create an XPath object to easily query the DOM.
        $this->xpath = new DOMXPath($this->dom);

        // Basic check: Does the XML have a root element, and is it <program>?
        if ($this->dom->documentElement === null || $this->dom->documentElement->tagName !== 'program') {
            throw new TypeException("Invalid XML structure: Missing or incorrect root element (expected '<program>')");
        }

        // Basic check: Does the <program> element have the language="SOL25" attribute?
        if (
            !$this->dom->documentElement->hasAttribute('language') ||
            strtoupper($this->dom->documentElement->getAttribute('language')) !== 'SOL25'
        ) {
            throw new TypeException("Invalid XML structure: Root '<program>' element is missing"
                . "or has incorrect 'language' attribute (expected 'SOL25')");
        }
    }

    /**
     * Creates the ClassManager and CallStack needed for interpretation.
     * Initializes the built-in class hierarchy.
     */
    private function initializeRuntime(): void
    {
        $this->classManager = new ClassManager();
        // Set up parent relationships for built-in classes (e.g., Integer inherits Object).
        $this->classManager->initializeBuiltInHierarchy();
        $this->callStack = new CallStack();
    }

    /**
     * Reads class definitions from the XML and populates the ClassManager.
     * Performs checks for valid names, inheritance, and method structure.
     *
     * @throws TypeException If class definitions are invalid (missing attributes, duplicates, bad names, etc.).
     * @throws InternalErrorException If XPath queries fail unexpectedly.
     * @throws MissingMainRunException If class 'Main' or its 'run' method is missing/invalid.
     */
    private function parseClasses(): void
    {
        if ($this->xpath === null || $this->classManager === null) {
            throw new InternalErrorException("Interpreter state not properly initialized before parseClasses.");
        }

        // Find all <class> elements directly under <program>.
        $classNodes = $this->xpath->query('/program/class');
        if ($classNodes === false) {
            throw new InternalErrorException("Failed to query class nodes.");
        }

        // First pass: Create UserDefinedClass objects for all user classes.
        // This allows forward references for parent classes.
        $userClasses = []; // Store XML elements temporarily.
        foreach ($classNodes as $classNode) {
            if (!$classNode instanceof DOMElement) {
                continue;
            }

            if (!$classNode->hasAttribute('name')) {
                throw new TypeException("Invalid XML structure: <class> tag missing 'name' attribute.");
            }
            $className = $classNode->getAttribute('name');

            if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $className) || $this->classManager->classExists($className)) {
                throw new TypeException("Invalid or duplicate class name found: '{$className}'");
            }


            $classDef = new UserDefinedClass($className);
            try {
                $this->classManager->addClass($classDef);
                $userClasses[$className] = $classNode;
            } catch (TypeException $e) {
                throw $e;
            }
        }

        // Second pass: Set parents and parse methods for each user class.
        foreach ($userClasses as $className => $classNode) {
            $classDef = $this->classManager->getClass($className);

            // Second pass: Set parents and parse methods for each user class.
            if (!$classNode->hasAttribute('parent')) {
                throw new TypeException("Invalid XML structure: <class name='{$className}'> tag"
                . "missing 'parent' attribute.");
            }
            $parentName = $classNode->getAttribute('parent');
            try {
                $parentDef = $this->classManager->getClass($parentName);
                $classDef->setParent($parentDef);
            } catch (TypeException $e) {
                throw new TypeException("Parent class '{$parentName}' for class '{$className}' not found.", $e);
            }

            $methodNodes = $this->xpath->query('./method', $classNode);
            if ($methodNodes === false) {
                throw new InternalErrorException("Failed to query method nodes for class '{$className}'.");
            }

            // Process each method definition.
            foreach ($methodNodes as $methodNode) {
                if (!$methodNode instanceof DOMElement) {
                    continue;
                }

                // Check for required 'selector' attribute.
                if (!$methodNode->hasAttribute('selector')) {
                    throw new TypeException("Invalid XML structure: <method> tag in"
                    . "class '{$className}' missing 'selector' attribute.");
                }
                $selector = $methodNode->getAttribute('selector');

                // Find the <block> element representing the method body. Expect exactly one.
                $blockNodes = $this->xpath->query('./block', $methodNode);
                if ($blockNodes === false || $blockNodes->length !== 1 || !$blockNodes[0] instanceof DOMElement) {
                    throw new TypeException("Invalid XML structure: Missing or" .
                        "invalid <block> inside <method selector='{$selector}'> in class '{$className}'.");
                }
                $blockElement = $blockNodes[0];

                // Check for the 'arity' attribute on the block (should be generated by parser)
                if (!$blockElement->hasAttribute('arity')) {
                    throw new TypeException("Invalid XML structure: <block> tag for"
                    . "method '{$selector}' in class '{$className}' missing 'arity' attribute.");
                }
                $blockArity = (int)$blockElement->getAttribute('arity');
                $selectorArity = substr_count($selector, ':');

                $methodBody = new BlockValue($blockElement, null);

                // Add the method (selector + block) to the class definition.
                try {
                    $classDef->addMethod($selector, $methodBody);
                } catch (TypeException $e) { // Catch if method selector is duplicated in the same class.
                    throw $e;
                }
            }
        }

        // Final check: Ensure the 'Main' class and its parameterless 'run' method exist.
        $mainClassDef = null;
        try {
            $mainClassDef = $this->classManager->getClass('Main');
        } catch (TypeException $e) {
            throw new MissingMainRunException("Execution failed: Class 'Main' not defined.", $e);
        }

        $runMethodBlock = $mainClassDef->findMethod('run');
        if ($runMethodBlock === null || $runMethodBlock->getArity() !== 0) {
            throw new MissingMainRunException("Execution failed: Class 'Main' does not"
            . "have a parameterless method 'run'.");
        }
    }

    /**
     * Evaluates an <expr> XML node and returns the resulting SOL25 value.
     * Delegates to specific methods based on the content of the <expr> tag.
     *
     * @param DOMElement $exprNode The <expr> node to evaluate.
     * @return BaseValue The resulting SOL25 value object.
     * @throws TypeException|InternalErrorException If the structure is invalid or state is wrong.
     */
    public function evaluateExpression(DOMElement $exprNode): BaseValue
    {
        if ($this->xpath === null || $this->callStack === null) {
            throw new InternalErrorException("Interpreter state not initialized for expression evaluation.");
        }

        // Find the single element inside the <expr> tag (literal, var, send, block).
        $contentNode = null;
        foreach ($exprNode->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if ($contentNode !== null) {
                    throw new TypeException("Invalid XML structure: <expr> node contains more than one element.");
                }
                $contentNode = $child;
            }
        }

        if ($contentNode === null) {
            throw new TypeException("Invalid XML structure: <expr> node is empty or contains no element.");
        }

        // Call the appropriate evaluation method based on the tag name.
        return match ($contentNode->tagName) {
            'literal' => $this->evaluateLiteral($contentNode),
            'var'     => $this->evaluateVariable($contentNode),
            'send'    => $this->evaluateSend($contentNode),
            'block'   => $this->evaluateBlockLiteral($contentNode),
            default   => throw new TypeException("Unexpected tag '{$contentNode->tagName}' inside <expr> node."),
        };
    }

    /**
     * Evaluates a <literal> XML node.
     *
     * @param DOMElement $literalNode The <literal> node.
     * @return BaseValue The corresponding SOL25 value (Nil, True, False, Integer, String).
     * @throws TypeException For invalid structure or unknown literal class.
     */
    private function evaluateLiteral(DOMElement $literalNode): BaseValue
    {
        // Check required 'class' attribute.
        if (!$literalNode->hasAttribute('class')) {
            throw new TypeException("Invalid XML structure: <literal> tag missing 'class' attribute.");
        }
        $literalClass = $literalNode->getAttribute('class');

        // Create the appropriate Value object based on the class attribute.
        switch ($literalClass) {
            case 'Nil':
                return NilValue::getInstance(); // Singleton pattern
            case 'True':
                return TrueValue::getInstance(); // Singleton pattern
            case 'False':
                return FalseValue::getInstance(); // Singleton pattern
            case 'Integer':
                if (!$literalNode->hasAttribute('value')) {
                    throw new TypeException("Invalid XML structure: <literal class='Integer'> tag"
                    . "missing 'value' attribute.");
                }
                // The XML value might contain escape sequences like \\n, \\', \\\\.
                // We need to convert them to actual characters.
                $value = $literalNode->getAttribute('value');
                return IntegerValue::fromInt((int)$value);
            case 'String':
                if (!$literalNode->hasAttribute('value')) {
                    throw new TypeException("Invalid XML structure: <literal class='String'> tag"
                        . "missing 'value' attribute.");
                }
                $rawValue = $literalNode->getAttribute('value');

                $interpretedValue = str_replace(
                    ['\\n', '\\\'', '\\\\'],
                    ["\n",  "'",    "\\"],
                    $rawValue
                );

                return StringValue::fromString($interpretedValue);
            default:
                throw new TypeException("Unexpected literal class type: '{$literalClass}'");
        }
    }

    /**
     * Evaluates a <block> XML node when it appears as a literal within an expression.
     * Creates a BlockValue object, capturing the current 'self' context.
     *
     * @param DOMElement $blockNode The <block> node.
     * @return BlockValue The SOL25 Block value.
     * @throws InternalErrorException If the call stack is empty (should not happen here).
     */
    private function evaluateBlockLiteral(DOMElement $blockNode): BlockValue
    {
        if ($this->callStack === null || $this->callStack->isEmpty()) {
            // This situation indicates a problem in the interpreter's flow.
            throw new InternalErrorException("Cannot evaluate block literal: Call stack is empty.");
        }
        // Get the 'self' object from the current execution frame.
        // This 'self' is remembered by the block object ('lexical scoping' for self).
        $currentFrame = $this->callStack->getCurrentFrame();
        $definedSelf = $currentFrame->getSelf();

        return new BlockValue($blockNode, $definedSelf);
    }

    /**
     * Evaluates a <var> XML node (variable access).
     * Looks up the variable/parameter in the current execution frame.
     *
     * @param DOMElement $varNode The <var> node.
     * @return BaseValue The value of the variable/parameter.
     * @throws TypeException If the <var> tag is missing the 'name' attribute.
     * @throws InternalErrorException If the call stack is empty.
     * @throws \IPP\Student\Exception\UndefinedVariableException If the variable is not defined.
     * @throws TypeException For accessing 'super' as a value or 'self' outside a method.
     */
    private function evaluateVariable(DOMElement $varNode): BaseValue
    {
        if (!$varNode->hasAttribute('name')) {
            throw new TypeException("Invalid XML structure: <var> tag missing 'name' attribute.");
        }
        $varName = $varNode->getAttribute('name');

        if ($this->callStack === null || $this->callStack->isEmpty()) {
            throw new InternalErrorException("Cannot evaluate variable '{$varName}': Call stack is empty.");
        }
        $currentFrame = $this->callStack->getCurrentFrame();

        // Ask the frame for the value associated with the name.
        // Frame::getVariableOrParameter handles lookup, keywords (nil, true, false, self), and errors.
        return $currentFrame->getVariableOrParameter($varName);
    }

    /**
     * Starts the program execution by finding the Main class, creating an instance,
     * and executing its 'run' method.
     *
     * @throws InternalErrorException If ClassManager is not ready or Main::run is unexpectedly missing.
     * @throws MissingMainRunException If Main or Main::run check fails (redundant check, but safe).
     * @throws TypeException If Main class definition is missing.
     * @throws DoNotUnderstandException|ValueException If errors occur during run method execution.
     */
    private function runMain(): void
    {
        if ($this->classManager === null) {
            throw new InternalErrorException("ClassManager not initialized before runMain.");
        }

        $mainClassDef = $this->classManager->getClass('Main');

        $mainInstance = new ObjectValue($mainClassDef);

        $runMethodBlock = $mainClassDef->findMethod('run');
        if ($runMethodBlock === null) {
            throw new InternalErrorException("Internal Error: Main::run method block not found"
                . "despite passing parseClasses check.");
        }
        if ($runMethodBlock->getArity() !== 0) {
            throw new InternalErrorException("Internal Error: Main::run method arity "
                . "is not 0 despite passing parseClasses check.");
        }

        // Execute the 'run' method's block.
        // Pass the $mainInstance as the 'self' context for this execution.
        $this->executeBlock($runMethodBlock, [], $mainInstance);
    }

    /**
     * Executes an <assign> XML node (statement).
     * Evaluates the expression on the right side and assigns the result to the variable on the left.
     *
     * @param DOMElement $assignNode The <assign> node.
     * @return BaseValue The value that was assigned (this is the result of the statement).
     * @throws InternalErrorException If state is invalid or node is not <assign>.
     * @throws TypeException If the XML structure inside <assign> is invalid.
     * @throws \IPP\Student\Exception\ParameterCollisionException If attempting to assign to a parameter.
     */
    private function executeStatement(DOMElement $assignNode): BaseValue
    {
        if ($this->xpath === null || $this->callStack === null || $this->callStack->isEmpty()) {
            throw new InternalErrorException("Interpreter state invalid or call stack empty" .
                "during statement execution.");
        }
        // Sanity check that we received an assign node.
        if ($assignNode->tagName !== 'assign') {
            throw new InternalErrorException("executeStatement called with non-assign node: "
                . $assignNode->tagName);
        }

        // Find the <var> (left side) and <expr> (right side) inside the <assign>.
        $varNodes = $this->xpath->query('./var', $assignNode);
        $exprNodes = $this->xpath->query('./expr', $assignNode);

        if (
            $varNodes === false || $varNodes->length !== 1 || !$varNodes[0] instanceof DOMElement ||
            $exprNodes === false || $exprNodes->length !== 1 || !$exprNodes[0] instanceof DOMElement
        ) {
            throw new TypeException("Invalid XML structure: <assign> node must"
                . "contain exactly one <var> and one <expr> child.");
        }

        $varNode = $varNodes[0];
        $exprNode = $exprNodes[0];

        // Get the variable name from the <var> tag.
        if (!$varNode->hasAttribute('name')) {
            throw new TypeException("Invalid XML structure: <var> tag"
                . "inside <assign> missing 'name' attribute.");
        }
        $varName = $varNode->getAttribute('name');

        $value = $this->evaluateExpression($exprNode);

        $currentFrame = $this->callStack->getCurrentFrame();

        // Define or update the variable in the current frame.
        // Frame::defineOrUpdateVariable handles checks for parameter collision and reserved names.
        $currentFrame->defineOrUpdateVariable($varName, $value);

        // Return the evaluated value (result of the assignment statement).
        return $value;
    }


    /**
     * Executes the code within a block.
     *
     * @param BlockValue $blockValue The block to execute.
     * @param array<int, BaseValue> $arguments The arguments to pass to the block's parameters.
     * @param BaseValue|null $instanceSelf The 'self' context if called as a method, null otherwise.
     * @return BaseValue The result of the block execution.
     * @throws DoNotUnderstandException|TypeException|ValueException|InternalErrorException|MethodRequiresExecutionException
     */
    public function executeBlock(BlockValue $blockValue, array $arguments, ?BaseValue $instanceSelf): BaseValue
    {
        if ($this->xpath === null || $this->callStack === null) {
            throw new InternalErrorException("Interpreter state not initialized for block execution.");
        }

        $expectedArity = $blockValue->getArity();
        $actualArity = count($arguments);
        if ($expectedArity !== $actualArity) {
            throw new DoNotUnderstandException(
                "Block execution arity mismatch: Expected {$expectedArity} arguments, got {$actualArity}."
            );
        }

        // Determine the 'self' for the new frame. Use $instanceSelf if provided (method call),
        // otherwise use the 'self' captured when the block literal was created.
        $selfForFrame = ($instanceSelf !== null) ? $instanceSelf : $blockValue->getDefinedSelf();

        // Create a new execution frame (scope) for this block.
        $newFrame = new Frame($selfForFrame);
        // Bind the passed arguments to the parameter names defined in the block.
        $parameterNames = $blockValue->getParameterNames();
        for ($i = 0; $i < $expectedArity; $i++) {
            /** @var string $paramName */
            $paramName = $parameterNames[$i];
            /** @var BaseValue $argValue */
            $argValue = $arguments[$i];
            // Frame::defineParameter handles validation (e.g., no reserved names).
            $newFrame->defineParameter($paramName, $argValue);
        }

        $returnValue = NilValue::getInstance();
        $this->callStack->push($newFrame);

        try {
            // Get the XML element for the block's body.
            $blockElement = $blockValue->getXmlElement();
            // Find all <assign> statements within the block.
            $assignNodes = $this->xpath->query('./assign', $blockElement);

            if ($assignNodes === false) {
                throw new InternalErrorException("Failed to query assign nodes within block element.");
            }

            // Collect statements and sort them by their 'order' attribute.
            $statements = [];
            foreach ($assignNodes as $node) {
                if ($node instanceof DOMElement && $node->hasAttribute('order')) {
                    $order = filter_var($node->getAttribute('order'), FILTER_VALIDATE_INT);
                    if ($order === false || $order < 1) {
                        throw new TypeException("Invalid 'order' attribute in <assign> tag inside block.");
                    }
                    $statements[$order] = $node;
                } else {
                    throw new TypeException("Invalid <assign> node structure"
                        . "or missing 'order' attribute inside block.");
                }
            }
            ksort($statements, SORT_NUMERIC);

            $lastResult = null;
            $executedStatements = 0;
            foreach ($statements as $order => $statementNode) {
                $lastResult = $this->executeStatement($statementNode);
                $executedStatements++;
            }

            if ($executedStatements > 0 && $lastResult !== null) {
                $returnValue = $lastResult;
            }
        } finally {
            $this->callStack->pop();
        }

        return $returnValue;
    }

    /**
     * Checks if a source class is compatible with a receiver class for the 'from:' message.
     * Compatibility means they are the same class, or one inherits from the other (directly or indirectly).
     *
     * @param UserDefinedClass $receiverClass The class receiving the 'from:' message.
     * @param UserDefinedClass $sourceClass The class of the object being passed as an argument.
     * @return bool True if compatible, false otherwise.
     */
    private function isCompatibleForFrom(UserDefinedClass $receiverClass, UserDefinedClass $sourceClass): bool
    {
        if ($receiverClass === $sourceClass) {
            return true;
        }

        $current = $sourceClass->getParent();
        while ($current !== null) {
            if ($current === $receiverClass) {
                return true;
            }
            $current = $current->getParent();
        }

        $current = $receiverClass->getParent();
        while ($current !== null) {
            if ($current === $sourceClass) {
                return true;
            }
            $current = $current->getParent();
        }

        return false;
    }


    /**
     * Helper function to find the single direct child element of a parent node.
     * Optionally checks if the child has a specific tag name.
     * Returns null if zero or more than one child element is found.
     *
     * @param DOMElement $parentNode The parent XML element.
     * @param string|null $expectedTagName The expected tag name of the child, or null to allow any tag.
     * @return DOMElement|null The found child element, or null.
     */
    private function findDirectChildElement(DOMElement $parentNode, ?string $expectedTagName): ?DOMElement
    {
        $foundNode = null;
        foreach ($parentNode->childNodes as $child) {
            if ($child instanceof DOMElement) {
                // Skip if tag name doesn't match the expected one (if specified).
                if ($expectedTagName !== null && $child->tagName !== $expectedTagName) {
                    continue;
                }
                // If we already found one element, then there's more than one -> error condition for caller.
                if ($foundNode !== null) {
                    return null;
                }
                $foundNode = $child;
            }
        }
        return $foundNode;
    }

    /**
     * Evaluates the receiver part of a <send> expression.
     * Determines if the receiver is a class literal, the 'super' keyword, or a regular expression result.
     *
     * @param DOMElement $receiverExprNode The <expr> node containing the receiver.
     * @return UserDefinedClass|BaseValue|string Returns the Class definition, the evaluated object, or SUPER_MARKER.
     * @throws TypeException If the receiver expression is invalid or refers to an undefined class.
     * @throws InternalErrorException If ClassManager is missing.
     */
    private function evaluateReceiverExpression(DOMElement $receiverExprNode): UserDefinedClass|BaseValue|string
    {
        // Find the actual content inside the receiver's <expr> tag.
        $contentNode = $this->findDirectChildElement($receiverExprNode, null);

        if ($contentNode === null) {
            throw new TypeException("Invalid XML structure: Receiver <expr> node is empty or has multiple elements.");
        }

        // Check if it's a class literal (e.g., <literal class="class" value="String">).
        if (
            $contentNode->tagName === 'literal' &&
            $contentNode->getAttribute('class') === 'class' &&
            $contentNode->hasAttribute('value')
        ) {
            $className = $contentNode->getAttribute('value');
            // Get the class definition from the manager.
            if ($this->classManager?->classExists($className)) {
                // Return the UserDefinedClass object itself.
                return $this->classManager->getClass($className);
            } else {
                // Class name used but not defined.
                throw new TypeException("Undefined class '{$className}' used as receiver.");
            }
        }
        // Check if it's the 'super' keyword (e.g., <var name="super">).
        if (
            $contentNode->tagName === 'var' &&
            $contentNode->hasAttribute('name') &&
            $contentNode->getAttribute('name') === 'super'
        ) {
            return self::SUPER_MARKER;
        }
        return $this->evaluateExpression($receiverExprNode);
    }

    /**
     * Evaluates a <send> XML node (message sending).
     * Handles class messages (new, from:, read), built-in methods, super calls,
     * block execution calls (value...), user-defined methods, and attribute access.
     *
     * @param DOMElement $sendNode The <send> node.
     * @return BaseValue The result of the message send.
     * @throws InternalErrorException | TypeException | DoNotUnderstandException | ValueException | MethodArityException
     */
    private function evaluateSend(DOMElement $sendNode): BaseValue
    {
        if (
            $this->xpath === null ||
            $this->classManager === null ||
            $this->callStack === null ||
            $this->callStack->isEmpty()
        ) {
            throw new InternalErrorException("Interpreter state not initialized for message send evaluation.");
        }

        if (!$sendNode->hasAttribute('selector')) {
            throw new TypeException("Invalid XML structure: <send> tag missing 'selector' attribute.");
        }
        $selector = $sendNode->getAttribute('selector');
        $expectedArity = substr_count($selector, ':');

        $receiverExprNode = $this->findDirectChildElement($sendNode, 'expr');
        if ($receiverExprNode === null) {
            throw new TypeException("Invalid XML structure: <send> tag must"
                . "have exactly one <expr> child for the receiver.");
        }

        // Evaluate the receiver expression. This can return:
        // - UserDefinedClass object (if receiver is a class name)
        // - BaseValue object (if receiver is an instance)
        // - SUPER_MARKER string (if receiver is 'super')
        $receiverOrMarker = $this->evaluateReceiverExpression($receiverExprNode);
        $isSuperCall = ($receiverOrMarker === self::SUPER_MARKER);
        $actualReceiver = null;
        $receiverValue = null;

        if ($isSuperCall) {
            $currentFrame = $this->callStack->getCurrentFrame();
            $actualReceiver = $currentFrame->getSelf();
            if ($actualReceiver === null) {
                throw new TypeException("Cannot use 'super' outside of a method context.");
            }
            $receiverValue = $actualReceiver;
            $logReceiverMarker = $actualReceiver->getSolClassName() . '#' . spl_object_id($actualReceiver);
        } elseif ($receiverOrMarker instanceof UserDefinedClass || $receiverOrMarker instanceof BaseValue) {
            $receiverValue = $receiverOrMarker;
            $logReceiverMarker = $receiverValue instanceof UserDefinedClass
                ? "Class<{$receiverValue->getName()}>"
                : $receiverValue->getSolClassName() . '#' . spl_object_id($receiverValue);
        } else {
            throw new InternalErrorException("Internal Error: evaluateReceiverExpression returned unexpected type.");
        }
        // Find and evaluate arguments (<arg order="N"><expr>...</expr></arg>).
        $argNodesRaw = $this->xpath->query('./arg', $sendNode);
        if ($argNodesRaw === false) {
            throw new InternalErrorException("Failed to query arg nodes.");
        }
        $argsByOrder = [];
        foreach ($argNodesRaw as $argNode) {
            if ($argNode instanceof DOMElement && $argNode->hasAttribute('order')) {
                $order = filter_var($argNode->getAttribute('order'), FILTER_VALIDATE_INT);
                $exprNode = $this->findDirectChildElement($argNode, 'expr');
                if ($order === false || $order < 1 || $exprNode === null) {
                    throw new TypeException("Invalid XML structure: <arg> node has"
                        . "invalid 'order' or missing/invalid <expr> child.");
                }
                if (isset($argsByOrder[$order])) {
                    throw new TypeException("Invalid XML structure: Duplicate order '{$order}' for <arg> in <send>.");
                }
                $argsByOrder[$order] = $exprNode;
            } else {
                throw new TypeException("Invalid XML structure: <arg> node missing 'order' or not an element.");
            }
        }
        ksort($argsByOrder, SORT_NUMERIC); // Ensure arguments are evaluated in the correct order (1, 2, 3...).

        // Evaluate each argument expression.
        $arguments = [];
        $argDebug = [];
        foreach ($argsByOrder as $order => $argExprNode) {
            $argValue = $this->evaluateExpression($argExprNode);
            $arguments[] = $argValue;
            $argClass = get_class($argValue);
            $argDebug[] = "Class:{$argClass}";
        }

        // Check if the number of evaluated arguments matches the selector's expected arity.
        if (count($arguments) !== $expectedArity) {
            throw new DoNotUnderstandException(
                "Message arity mismatch for selector '{$selector}':"
                . "Expected {$expectedArity} arguments, got " . count($arguments) . "."
            );
        }

        // --- Message Handling ---

        // 1. Handle Class Messages (receiver is a UserDefinedClass object).
        if ($receiverValue instanceof UserDefinedClass) {
            $receiverClassName = $receiverValue->getName();
            switch ($selector) {
                case 'new': // Object creation (parameterless).
                    if ($expectedArity !== 0) {
                        throw new DoNotUnderstandException("Arity mismatch for 'new'");
                    }
                    $newClassDef = $receiverValue;
                    $newClassName = $newClassDef->getName();


                    // Handle creation of built-in types (singletons or default values).
                    switch ($newClassName) {
                        case 'Nil':
                            return NilValue::getInstance();
                        case 'True':
                            return TrueValue::getInstance();
                        case 'False':
                            return FalseValue::getInstance();
                        case 'Integer':
                            return IntegerValue::fromInt(0);
                        case 'String':
                            return StringValue::fromString('');
                        case 'Block':
                            throw new TypeException("Cannot create default 'Block' instance using 'new'.");
                        default:// User-defined class or Object.
                            // Create a basic ObjectValue associated with the class definition.
                            $newInstance = new ObjectValue($newClassDef);

                            // Special initialization for subclasses of Integer/String: set default internal value.
                            $ancestor = $newClassDef;
                            $hasIntegerAncestor = false;
                            $hasStringAncestor = false;

                            while ($ancestor !== null) {
                                $ancestorName = $ancestor->getName();
                                if ($ancestorName === 'Integer') {
                                    $hasIntegerAncestor = true;
                                    break;
                                }
                                if ($ancestorName === 'String') {
                                    $hasStringAncestor = true;
                                }
                                if ($ancestorName === 'Object') {
                                    break;
                                }
                                $ancestor = $ancestor->getParent();
                            }

                            // If inheriting Integer, set internal value to 0.
                            if ($hasIntegerAncestor) {
                                try {
                                    $newInstance->sendMessage('__internal_value:', [IntegerValue::fromInt(0)]);
                                } catch (Throwable $e) {
                                    throw new InternalErrorException("Failed to set default internal Integer"
                                        . "value during 'new' for {$newClassName}.", $e);
                                }
                            } elseif ($hasStringAncestor) {
                                // If inheriting String (but not Integer), set internal value to ''.
                                try {
                                    $newInstance->sendMessage('__internal_value:', [StringValue::fromString('')]);
                                } catch (Throwable $e) {
                                    throw new InternalErrorException("Failed to set default internal String"
                                        . "value during 'new' for {$newClassName}.", $e);
                                }
                            } else {
                            }
                            return $newInstance;
                    }

                case 'from:': // Object creation/copying from another object.
                    if ($expectedArity !== 1) {
                        throw new DoNotUnderstandException("Arity mismatch for 'from:'");
                    }
                    $sourceObj = $arguments[0];
                    $sourceClassName = $sourceObj->getSolClassName();
                    $sourceClassDef = $this->classManager->getClass($sourceClassName);
                    // Check if the source object's class is compatible with the receiving class.
                    if (!$this->isCompatibleForFrom($receiverValue, $sourceClassDef)) {
                        throw new ValueException("Invalid argument for 'from:':"
                            . "Class '{$sourceClassName}' is incompatible with receiver '{$receiverClassName}'.");
                    }

                    // Handle 'from:' for specific built-in types.
                    switch ($receiverClassName) {
                        case 'Nil':
                            return NilValue::getInstance();
                        case 'True':
                            return TrueValue::getInstance();
                        case 'False':
                            return FalseValue::getInstance();
                        case 'Integer':
                            if (!$sourceObj instanceof IntegerValue) {
                                throw new ValueException("Invalid argument for 'Integer from:':"
                                    . "expected Integer, got {$sourceClassName}.");
                            }
                            return IntegerValue::fromInt($sourceObj->getInternalValue());
                        case 'String':
                            if (!$sourceObj instanceof StringValue) {
                                throw new ValueException("Invalid argument for 'String from:':"
                                    . "expected String, got {$sourceClassName}.");
                            }
                            return StringValue::fromString($sourceObj->getInternalValue());
                        case 'Block':
                            throw new TypeException("Cannot create 'Block' instance using 'from:'.");
                        case 'Object':
                        default:
                            /** @var UserDefinedClass $receiverValue */
                            $newInstance = new ObjectValue($receiverValue);

                            // Special initialization for subclasses of Integer/String: set internal value.
                            if ($sourceObj instanceof IntegerValue || $sourceObj instanceof StringValue) {
                                try {
                                    $newInstance->sendMessage('__internal_value:', [$sourceObj]);
                                } catch (Throwable $e) {
                                    throw new InternalErrorException("Failed to set internal"
                                        . "value attribute during 'from:'.", $e);
                                }
                            } elseif ($sourceObj instanceof ObjectValue) {
                                /** @var array<string, BaseValue> $sourceAttributes */
                                $sourceAttributes = $sourceObj->getAttributes();

                                // Copy all attributes from the source object to the new instance.
                                foreach ($sourceAttributes as $attrName => $attrValue) {
                                    if ($attrName === '__internal_value') {
                                        continue;
                                    }
                                    $setterSelector = $attrName . ':';
                                    try { // Attempt to set the attribute on the new instance.
                                        $newInstance->sendMessage($setterSelector, [$attrValue]);
                                    } catch (Throwable $e) {
                                        throw new InternalErrorException(
                                            "Failed to copy attribute '{$attrName}' using"
                                            . "selector '{$setterSelector}' during 'from:'.",
                                            $e
                                        );
                                    }
                                }
                            }
                            return $newInstance;
                    }

                case 'read': // Read a line from the input.
                    if ($receiverClassName !== 'String') {
                        throw new DoNotUnderstandException("Class '{$receiverClassName}' does"
                            . "not understand class message '{$selector}'");
                    }
                    if ($expectedArity !== 0) {
                        throw new DoNotUnderstandException("Arity mismatch for 'String read'");
                    }

                    $line = $this->input->readString();

                    return StringValue::fromString($line ?? ''); // Return empty string if null.

                default:
                    throw new DoNotUnderstandException("Class '{$receiverClassName}' does"
                        . "not understand class message '{$selector}'");
            }
        }

        // 2. Handle built-in methods (e.g., 'print', 'printString', 'printInteger').
        if ($receiverValue instanceof BlockValue && str_starts_with($selector, 'value')) {
            // Handle block value calls (e.g., 'value:', 'value:value:').
            $expectedArityFromSelector = substr_count($selector, ':');
            if (
                $expectedArityFromSelector !== count($arguments) ||
                $expectedArityFromSelector !== $receiverValue->getArity()
            ) {
                throw new DoNotUnderstandException(
                    "Block value call arity mismatch: Selector '{$selector}' implies {$expectedArityFromSelector} args,"
                    . "block expects {$receiverValue->getArity()}, got " . count($arguments) . " args."
                );
            }
            return $this->executeBlock($receiverValue, $arguments, null);
        }

        // 3. Handle built-in methods (e.g., 'print', 'printString', 'printInteger').
        if ($receiverValue instanceof TrueValue || $receiverValue instanceof FalseValue) {
            // Handle boolean methods (e.g., 'ifTrue:', 'ifFalse:', 'and:', 'or:').
            switch ($selector) {
                case 'ifTrue:ifFalse:': // Conditional execution based on the receiver value.
                    if ($expectedArity !== 2) {
                        throw new DoNotUnderstandException("Arity mismatch for 'ifTrue:ifFalse:'");
                    }

                    // Determine which argument to evaluate based on the receiver value.
                    $argumentToEvaluate = ($receiverValue instanceof TrueValue) ? $arguments[0] : $arguments[1];
                    $branch = ($receiverValue instanceof TrueValue) ? 'TRUE' : 'FALSE';

                    // Check if the argument is a block or a method call.
                    if ($argumentToEvaluate instanceof BlockValue) {
                        if ($argumentToEvaluate->getArity() !== 0) {
                            throw new TypeException("Block argument for 'ifTrue:ifFalse:' ({$branch} branch) must be"
                                . "parameterless (understand 'value'), but has arity "
                                . $argumentToEvaluate->getArity());
                        }
                        return $this->executeBlock($argumentToEvaluate, [], null);
                    } else {
                        return $this->evaluateZeroArityBlockOrMethod(
                            $argumentToEvaluate,
                            "'ifTrue:ifFalse:' ({$branch} branch)"
                        );
                    }

                case 'and:': // Logical AND operation.
                    if ($expectedArity !== 1) {
                        throw new DoNotUnderstandException("Arity mismatch for 'and:'");
                    }
                    if ($receiverValue instanceof FalseValue) {
                        return $receiverValue;
                    } else {
                        $argumentObject = $arguments[0];

                        // Check if the argument is a block or a method call.
                        if ($argumentObject instanceof BlockValue) {
                            if ($argumentObject->getArity() !== 0) {
                                throw new TypeException("Block argument for 'and:' must"
                                    . "be parameterless (understand 'value'), but has arity "
                                    . $argumentObject->getArity());
                            }
                            return $this->executeBlock($argumentObject, [], null);
                        } else {
                            return $this->evaluateZeroArityBlockOrMethod(
                                $argumentObject,
                                "'and:' argument"
                            );
                        }
                    }

                case 'or:': // Logical OR operation.
                    if ($expectedArity !== 1) {
                        throw new DoNotUnderstandException("Arity mismatch for 'or:'");
                    }
                    if ($receiverValue instanceof TrueValue) {
                        return $receiverValue;
                    } else {
                        $argumentObject = $arguments[0];

                        // Check if the argument is a block or a method call.
                        if ($argumentObject instanceof BlockValue) {
                            if ($argumentObject->getArity() !== 0) {
                                throw new TypeException("Block argument for 'or:' must"
                                    . "be parameterless (understand 'value'), but has arity "
                                    . $argumentObject->getArity());
                            }
                            return $this->executeBlock($argumentObject, [], null);
                        } else {
                            return $this->evaluateZeroArityBlockOrMethod($argumentObject, "'or:' argument");
                        }
                    }
            }
        }

        // 4. Handle built-in methods for blocks and objects.
        $isReceiverBlockCompatible = false;
        $conditionObject = null;
        if ($receiverValue instanceof BlockValue) {
            $isReceiverBlockCompatible = true;
            $conditionObject = $receiverValue;
        } elseif ($receiverValue instanceof ObjectValue) { // Check if the receiver is an object.
            $receiverClassInfo = $receiverValue->getClassInfo();
            try {
                // Check if the receiver class is a subclass of Block.
                $blockClassDef = $this->classManager->getClass('Block');
                $parent = $receiverClassInfo;
                while ($parent !== null) { // Traverse the class hierarchy.
                    if ($parent === $blockClassDef) {
                        $isReceiverBlockCompatible = true;
                        $conditionObject = $receiverValue;
                        break;
                    }
                    if ($parent === $parent->getParent() && $parent->getName() !== 'Object') {
                        break;
                    }
                    $parent = $parent->getParent();
                }
            } catch (TypeException $e) {
                throw new InternalErrorException("Could not find built-in Block class definition.", $e);
            }
        }

        // Handle block messages (e.g., 'whileTrue:', 'timesRepeat:').
        if ($isReceiverBlockCompatible && $selector === 'whileTrue:') {
            if ($expectedArity !== 1) {
                throw new DoNotUnderstandException("Arity mismatch for 'whileTrue:'");
            }

            $loopBodyObject = $arguments[0];

            // Check if the loop body is a block or a method call.
            $executeLoopBody = function () use ($loopBodyObject) {
                if ($loopBodyObject instanceof BlockValue) {
                    if ($loopBodyObject->getArity() !== 0) {
                        throw new TypeException("Block argument for 'whileTrue:' must"
                            . "be parameterless, but has arity " . $loopBodyObject->getArity());
                    }
                    $this->executeBlock($loopBodyObject, [], $loopBodyObject->getDefinedSelf());
                } else {
                    $this->evaluateZeroArityBlockOrMethod($loopBodyObject, "'whileTrue:' loop body");
                }
            };
            while (true) {
                $conditionResult = NilValue::getInstance();
                // Check if the condition is a block or a method call.
                if ($conditionObject instanceof BlockValue) {
                    if ($conditionObject->getArity() !== 0) {
                        throw new TypeException("Block receiver for 'whileTrue:' must"
                            . "be parameterless, but has arity " . $conditionObject->getArity());
                    }
                    // Execute the block and get the result.
                    $conditionResult = $this->executeBlock($conditionObject, [], $conditionObject->getDefinedSelf());
                } elseif ($conditionObject instanceof ObjectValue) {
                    $conditionResult = $this->evaluateZeroArityBlockOrMethod(
                        $conditionObject,
                        "'whileTrue:' condition"
                    );
                } else {
                    throw new InternalErrorException("Unexpected condition object type in whileTrue loop.");
                }

                if (!$conditionResult instanceof TrueValue) {
                    break;
                }

                $executeLoopBody();
            }

            return NilValue::getInstance();
        }

        // Handle built-in methods for String and Integer classes.
        if ($receiverValue instanceof StringValue && $selector === 'print') {
            if ($expectedArity !== 0) {
                throw new DoNotUnderstandException("Arity mismatch for 'print'");
            }
            $this->stdout->writeString($receiverValue->getInternalValue());
            return $receiverValue;
        }

        if ($receiverValue instanceof ObjectValue && $selector === 'print') {
            if ($expectedArity !== 0) {
                throw new DoNotUnderstandException("Arity mismatch for 'print'");
            }
            try {
                $receiverValue->sendMessage($selector, $arguments);
            } catch (DoNotUnderstandException | TypeException | ValueException $e) {
                throw $e;
            } catch (MethodRequiresExecutionException $mree) {
                $methodBlock = $mree->blockValue;
                if ($methodBlock->getArity() !== $expectedArity) {
                    throw new DoNotUnderstandException("Arity mismatch for 'print'");
                }
                return $this->executeBlock($methodBlock, $arguments, $receiverValue);
            }

            $internalValue = $receiverValue->getAttributes()['__internal_value'] ?? null;
            if ($internalValue instanceof StringValue) {
                $this->stdout->writeString($internalValue->getInternalValue());
            }
            return $receiverValue;
        }

        // Handle built-in methods for Integer class.
        if ($receiverValue instanceof IntegerValue && $selector === 'timesRepeat:') {
            if ($expectedArity !== 1) {
                throw new DoNotUnderstandException("Arity mismatch for 'timesRepeat:'");
            }
            $loopCount = $receiverValue->getInternalValue();
            $loopBodyObject = $arguments[0];

            // Check if the loop body is a block or a method call.
            if ($loopCount > 0) {
                for ($i = 1; $i <= $loopCount; $i++) {
                    // Create an IntegerValue for the current iteration.
                    $iterationArg = IntegerValue::fromInt($i);

                    // Check if the loop body is a block or a method call.
                    if ($loopBodyObject instanceof BlockValue) {
                        if ($loopBodyObject->getArity() !== 1) {
                            throw new TypeException("Block literal passed to timesRepeat: must"
                                . "have exactly one parameter (for iteration number), got "
                                . $loopBodyObject->getArity());
                        }
                        $this->executeBlock($loopBodyObject, [$iterationArg], $loopBodyObject->getDefinedSelf());
                    } else {
                        $this->evaluateOneArityBlockOrMethod(
                            $loopBodyObject,
                            $iterationArg,
                            "'timesRepeat:' loop body"
                        );
                    }
                }
            }

            return NilValue::getInstance();
        }

        // 5. Handle user-defined methods (e.g., 'printString', 'printInteger').
        try {
            if ($isSuperCall) { // Handle 'super' calls.
                if (!$actualReceiver instanceof ObjectValue) { // 'super' can only be used with ObjectValue instances.
                    throw new TypeException("'super' can only be used"
                        . "with instances of user-defined classes or Object.");
                }
                $receiverClass = $actualReceiver->getClassInfo();
                $methodBlock = $receiverClass->findMethodInParent($selector);

                // Check if the method is defined in the parent class.
                if ($methodBlock === null) {
                    // Attempt to delegate the method call to the internal value of the receiver.
                    if ($actualReceiver instanceof ObjectValue) {
                        // Attempt to find the internal value of the receiver.
                        $internalValue = $actualReceiver->getAttributes()['__internal_value'] ?? null;
                        $delegatableSelectors = [
                            'equalTo:', 'greaterThan:', 'plus:', 'minus:', 'multiplyBy:', 'divBy:',
                            'asString', 'asInteger', 'timesRepeat:',
                            'concatenateWith:', 'startsWith:endsBefore:'
                        ];

                        // Check if the internal value is an instance of BaseValue and if the selector is delegatable.
                        if ($internalValue instanceof BaseValue && in_array($selector, $delegatableSelectors, true)) {
                            // Attempt to send the message to the internal value.
                            try {
                                $unwrappedArgs = [];
                                // Unwrap arguments if they are ObjectValues with internal values.
                                foreach ($arguments as $arg) {
                                    // Check if the argument is an ObjectValue with an internal value.
                                    if (
                                        $internalValue instanceof IntegerValue &&
                                        $arg instanceof ObjectValue &&
                                        isset($arg->getAttributes()['__internal_value']) &&
                                        $arg->getAttributes()['__internal_value'] instanceof IntegerValue
                                    ) {
                                        $unwrappedArgs[] = $arg->getAttributes()['__internal_value'];
                                    } elseif (
                                        $internalValue instanceof StringValue &&
                                        $arg instanceof ObjectValue &&
                                        isset($arg->getAttributes()['__internal_value']) &&
                                        $arg->getAttributes()['__internal_value'] instanceof StringValue
                                    ) {
                                        $unwrappedArgs[] = $arg->getAttributes()['__internal_value'];
                                    } else {
                                        $unwrappedArgs[] = $arg;
                                    }
                                }
                                return $internalValue->sendMessage($selector, $unwrappedArgs);
                            } catch (DoNotUnderstandException $e) {
                            }
                        }
                    }
                    throw new DoNotUnderstandException("Inherited method '{$selector}' not"
                        . "found via delegation in super context for '{$receiverClass->getName()}'.");
                }
                // Check if the method is defined in the parent class.
                $expectedMethodAritySuper = $methodBlock->getArity();
                $actualArgumentCountSuper = count($arguments);
                // Check if the number of arguments matches the expected arity.
                if ($expectedMethodAritySuper !== $actualArgumentCountSuper) {
                    throw new MethodArityException("Runtime arity mismatch for method '{$selector}' in"
                        . "super context: Expected {$expectedMethodAritySuper} arguments,"
                        . "got {$actualArgumentCountSuper}.");
                }
                return $this->executeBlock($methodBlock, $arguments, $actualReceiver);
            } else {
                return $receiverValue->sendMessage($selector, $arguments);
            }
        } catch (MethodRequiresExecutionException $mree) {
            $methodBlock = $mree->blockValue;
            $expectedMethodArity = $methodBlock->getArity();
            $actualArgumentCount = count($arguments);
            if ($expectedMethodArity !== $actualArgumentCount) {
                throw new MethodArityException("Runtime arity mismatch for method '{$selector}':"
                    . "Expected {$expectedMethodArity} arguments, got {$actualArgumentCount}.");
            }
            return $this->executeBlock($methodBlock, $arguments, $receiverValue);
        } catch (DoNotUnderstandException | TypeException | ValueException $runtimeError) {
            throw $runtimeError;
        }
    }
}
