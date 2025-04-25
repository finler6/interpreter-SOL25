<?php

declare(strict_types=1);

namespace IPP\Student\Values;

// Import necessary PHP built-in classes for XML handling.
use DOMElement;
use DOMXPath;
// Import necessary exceptions and base value class.
use IPP\Core\Exception\InternalErrorException;
use IPP\Student\Values\BaseValue;
use IPP\Student\Values\TrueValue;

/**
 * Represents a Block literal value in the SOL25 language.
 * This object doesn't contain the executable code directly, but holds:
 * - A reference to the <block> XML element from the AST.
 * - The 'self' context captured when the block literal was defined (lexical self).
 * The Interpreter uses this information to execute the block when needed.
 */
class BlockValue extends BaseValue
{
    /**
     * The DOMElement representing the <block> tag in the XML source.
     * This contains the parameters and assignment statements of the block.
     * @var DOMElement
     */
    private DOMElement $blockElement;

    /**
     * An ordered list of parameter names defined in the block.
     * Example: for [:x :y| ...], this would be ["x", "y"].
     * @var array<int, string>
     */
    private array $parameterNames = [];

    /**
     * The number of parameters the block expects (its arity).
     * Determined by the number of <parameter> tags found.
     * @var int
     */
    private int $arity;

    /**
     * The 'self' object that was active when this block literal was created.
     * This is used as the 'self' context when the block is executed later,
     * implementing lexical scoping for 'self'.
     * It's null if the block was defined outside a method context (e.g., top level in Main::run).
     * @var BaseValue|null
     */
    private ?BaseValue $definedSelf;

    /**
     * Constructor for BlockValue.
     *
     * @param DOMElement $blockElement The <block> XML element from the source.
     * @param BaseValue|null $definedSelf The 'self' context captured at definition time.
     * @throws InternalErrorException If the passed DOMElement is not a <block> tag.
     */
    public function __construct(DOMElement $blockElement, ?BaseValue $definedSelf)
    {
        parent::__construct('Block'); // Set the SOL25 class name.

        // Basic validation: ensure the passed element is actually a <block>.
        if ($blockElement->tagName !== 'block') {
            throw new InternalErrorException("Invalid DOMElement passed to BlockValue constructor:"
                . "expected 'block', got '$blockElement->tagName'");
        }

        $this->blockElement = $blockElement;
        $this->definedSelf = $definedSelf;
        // Read parameter names and calculate arity immediately upon creation.
        $this->extractParameters();
    }

    /**
     * Private helper method to read parameter definitions from the XML element.
     * Populates $this->parameterNames and sets $this->arity.
     * Ensures parameters have valid 'name' and 'order' attributes and are sequential.
     *
     * @throws InternalErrorException If the XML structure for parameters is invalid or XPath fails.
     */
    private function extractParameters(): void
    {
        $this->parameterNames = []; // Reset just in case.
        $params = []; // Temporary array to store parameters keyed by order.

        // Get the owner document to create an XPath object.
        $ownerDoc = $this->blockElement->ownerDocument;
        if ($ownerDoc === null) {
            throw new InternalErrorException("Block element is not associated with a document.");
        }
        $xpath = new DOMXPath($ownerDoc);

        // Query for direct <parameter> children of the <block> element.
        $parameterNodes = $xpath->query('./parameter', $this->blockElement);

        // Check if the XPath query itself failed.
        if ($parameterNodes === false) {
            throw new InternalErrorException("Failed to query parameters within block element.");
        }

        // Process each found <parameter> node.
        foreach ($parameterNodes as $node) {
            // Ensure it's an element with the required 'name' and 'order' attributes.
            if ($node instanceof DOMElement && $node->hasAttribute('name') && $node->hasAttribute('order')) {
                $order = (int)$node->getAttribute('order'); // Convert order to integer.
                $name = $node->getAttribute('name');
                $params[$order] = $name; // Store name using order as the key.
            } else {
                // Malformed parameter tag found.
                throw new InternalErrorException("Invalid <parameter> node structure found in block.");
            }
        }

        // Sort the parameters based on their 'order' attribute (which are the array keys).
        ksort($params, SORT_NUMERIC);

        // Create the final ordered list of parameter names.
        $this->parameterNames = array_values($params);
        // Set the arity based on the number of parameters found.
        $this->arity = count($this->parameterNames);

        // Validate that the 'order' attributes were sequential (1, 2, 3...).
        $expectedOrder = 1;
        foreach (array_keys($params) as $order) {
            if ($order !== $expectedOrder) {
                throw new InternalErrorException("Parameter order is not sequential starting from 1 in block.");
            }
            $expectedOrder++;
        }
    }

    /**
     * Gets the underlying DOMElement representing the block.
     * Used by the Interpreter to find and execute the statements inside.
     * @return DOMElement The <block> XML element.
     */
    public function getXmlElement(): DOMElement
    {
        return $this->blockElement;
    }

    /**
     * Returns the names of the block's parameters in the correct order.
     * Used by the Interpreter when creating a new Frame for block execution.
     * @return array<int, string> Ordered list of parameter names.
     */
    public function getParameterNames(): array
    {
        return $this->parameterNames;
    }

    /**
     * Gets the number of parameters this block expects.
     * @return int The block's arity.
     */
    public function getArity(): int
    {
        return $this->arity;
    }

    /**
     * Gets the 'self' object that was captured when this block literal was defined.
     * Returns null if the block was defined outside a method context.
     * @return BaseValue|null The captured 'self' object.
     */
    public function getDefinedSelf(): ?BaseValue
    {
        return $this->definedSelf;
    }

    /**
     * Handles the 'isBlock' message.
     * Always returns TrueValue for BlockValue instances.
     * @return BaseValue The singleton TrueValue instance.
     */
    public function methodIsBlock(): BaseValue
    {
        return TrueValue::getInstance();
    }
}
