<?php

declare(strict_types=1);

namespace Php2Pdf\Structure;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Objects;
use Php2Pdf\Core\TextString;

use function array_map;
use function count;
use function is_int;
use function ksort;

/**
 * A document's logical structure: a Document element and the elements under it in reading
 * order, and the parent tree that leads from each page's marked content and each tagged
 * annotation back to its element (ISO 32000-1, 14.7).
 *
 * @internal
 */
final class StructureTree
{
    public readonly StructureElement $root;

    /** @var array<int, PageMarks|StructureElement> parent tree entries: a page's marks or an annotation's element, by key */
    private array $entries = [];

    public function __construct(
        private readonly Objects $objects,
    ) {
        $this->root = new StructureElement(StructureRole::Document, null);
    }

    /** Tags an annotation on a page as part of an element; its /StructParent key. */
    public function annotation(StructureElement $element, Reference $page, Reference $annotation): int
    {
        $key                 = count($this->entries);
        $this->entries[$key] = $element;
        $element->addAnnotation($page, $annotation);

        return $key;
    }

    /** A new element under a parent, which it joins when its first content is drawn. */
    public function element(
        StructureRole $role,
        StructureElement $parent,
        ?string $alt = null,
        ?Dictionary $attributes = null,
    ): StructureElement {
        return new StructureElement($role, $parent, $alt, $attributes);
    }

    /** The marks of a page, whose /StructParents is their key. */
    public function page(Reference $page): PageMarks
    {
        $marks                      = new PageMarks($page, count($this->entries));
        $this->entries[$marks->key] = $marks;

        return $marks;
    }

    /** Writes the elements and the tree, and returns the /StructTreeRoot. */
    public function write(): Reference
    {
        $reference = $this->objects->allocate();
        $this->writeElement($this->root, $reference);
        $nums = [];
        ksort($this->entries);
        foreach ($this->entries as $key => $entry) {
            $nums[] = $key;
            $nums[] = $entry instanceof PageMarks
                ? array_map(
                    fn(StructureElement $element): Reference => $element->reference($this->objects),
                    $entry->elements(),
                )
                : $entry->reference($this->objects);
        }
        $this->objects->write($reference, Dictionary::of([
            'Type'              => new Name('StructTreeRoot'),
            'K'                 => $this->root->reference($this->objects),
            'ParentTree'        => $this->objects->add(Dictionary::of(['Nums' => $nums])),
            'ParentTreeNextKey' => count($this->entries),
        ]));

        return $reference;
    }

    /**
     * Writes an element and the elements under it: its first marked content's page as its /Pg,
     * and other pages' content and annotations as marked-content and object references.
     */
    private function writeElement(StructureElement $element, Reference $parent): void
    {
        $page = null;
        $kids = [];
        foreach ($element->kids() as $kid) {
            if ($kid instanceof StructureElement) {
                $this->writeElement($kid, $element->reference($this->objects));
                $kids[] = $kid->reference($this->objects);
                continue;
            }
            [$on, $what] = $kid;
            $page   ??= $on;
            $kids[] = match (true) {
                is_int($what) && $on === $page => $what,
                is_int($what) => Dictionary::of(['Type' => new Name('MCR'), 'Pg' => $on, 'MCID' => $what]),
                default       => Dictionary::of(['Type' => new Name('OBJR'), 'Obj' => $what, 'Pg' => $on]),
            };
        }
        $this->objects->write(
            $element->reference($this->objects),
            Dictionary::of([
                'Type' => new Name('StructElem'),
                'S'    => new Name($element->role->value),
                'P'    => $parent,
                'Pg'   => $page,
                'K'    => $kids,
                'Alt'  => null === $element->alt ? null : TextString::encode($element->alt),
                'A'    => $element->attributes,
            ]),
        );
    }
}
