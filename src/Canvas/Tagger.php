<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Structure\PageMarks;
use Php2Pdf\Structure\StructureElement;
use Php2Pdf\Structure\StructureRole;
use Php2Pdf\Structure\StructureTree;

use function array_key_last;
use function array_pop;
use function count;

/**
 * What a tagged page's content is drawn as: the elements being drawn, innermost last, or an
 * artifact. Content drawn outside them all is an artifact.
 *
 * @internal
 */
final class Tagger
{
    /** @var list<StructureElement|false> what is being drawn, innermost last, false for an artifact */
    private array $stack = [];

    public function __construct(
        private readonly StructureTree $tree,
        private readonly PageMarks $marks,
    ) {}

    /** Tags an annotation on the page as part of an element; its /StructParent key. */
    public function annotation(StructureElement $element, Reference $annotation): int
    {
        return $this->tree->annotation($element, $this->marks->page, $annotation);
    }

    /** The innermost element being drawn, or the document's when there is none. */
    public function current(): StructureElement
    {
        for ($i = count($this->stack) - 1; $i >= 0; --$i) {
            $element = $this->stack[$i] ?? false;
            if (false !== $element) {
                return $element;
            }
        }

        return $this->tree->root;
    }

    /** A new element, the last kid of the given parent or of the innermost element being drawn. */
    public function element(
        StructureRole $role,
        ?string $alt = null,
        ?Dictionary $attributes = null,
        ?StructureElement $parent = null,
    ): StructureElement {
        return $this->tree->element($role, $parent ?? $this->current(), $alt, $attributes);
    }

    /** Starts drawing an element, or an artifact for null, inside what is being drawn. */
    public function enter(?StructureElement $element): void
    {
        $this->stack[] = $element ?? false;
    }

    /** Whether what is being drawn is an artifact, which nothing drawn in it is tagged in. */
    public function isArtifact(): bool
    {
        return false === ($this->stack[count($this->stack) - 1] ?? null);
    }

    /** Whether what is drawn now is the element, or an artifact for null. */
    public function isDrawing(?StructureElement $element): bool
    {
        return [] !== $this->stack && ($this->stack[count($this->stack) - 1] ?? null) === ($element ?? false);
    }

    /** Stops drawing what enter() started last. */
    public function leave(): void
    {
        array_pop($this->stack);
    }

    /** The page's marks, whose page reference the page is written to. */
    public function marks(): PageMarks
    {
        return $this->marks;
    }

    /** The operator that opens a marked-content sequence for what is being drawn. */
    public function opening(): string
    {
        $element = $this->stack[count($this->stack) - 1] ?? false;
        if (false === $element) {
            return '/Artifact BMC';
        }

        return "/{$element->role->value} <</MCID {$this->marks->mark($element)}>> BDC";
    }

    /** Draws the element, or an artifact for null, in place of what enter() started last. */
    public function switchTo(?StructureElement $element): void
    {
        $last = array_key_last($this->stack);
        if (null !== $last) {
            $this->stack[$last] = $element ?? false;
        }
    }
}
