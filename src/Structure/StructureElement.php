<?php

declare(strict_types=1);

namespace Php2Pdf\Structure;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Objects;

/**
 * An element of a document's structure tree: its role, its parent, and its kids in reading
 * order, each another element, a marked-content sequence on a page, or an annotation; with its
 * alternate description and attributes, if any. An element joins its parent's kids when its
 * first content is drawn, so the kids are in the order they are drawn, and an element nothing is
 * drawn in is left out.
 *
 * @internal
 */
final class StructureElement
{
    /**
     * @var list<StructureElement|array{0: Reference, 1: int}|array{0: Reference, 1: Reference}> the
     *      kids: elements, marked content as page and MCID, annotations as page and annotation
     */
    private array $kids = [];

    private bool $attached;
    private ?Reference $reference = null;

    public function __construct(
        public readonly StructureRole $role,
        public readonly ?self $parent,
        public readonly ?string $alt = null,
        public readonly ?Dictionary $attributes = null,
    ) {
        $this->attached = null === $parent;
    }

    public function addAnnotation(Reference $page, Reference $annotation): void
    {
        $this->attach();
        $this->kids[] = [$page, $annotation];
    }

    public function addContent(Reference $page, int $mcid): void
    {
        $this->attach();
        $this->kids[] = [$page, $mcid];
    }

    /**
     * Joins the element, and those above it that have not, to their parents' kids: done when
     * its first content is drawn, or to keep an element that has none, such as an empty cell.
     */
    public function attach(): void
    {
        if ($this->attached || null === $this->parent) {
            return;
        }
        $this->attached = true;
        $this->parent->attach();
        $this->parent->kids[] = $this;
    }

    /** @return list<StructureElement|array{0: Reference, 1: int}|array{0: Reference, 1: Reference}> */
    public function kids(): array
    {
        return $this->kids;
    }

    /** The element's object, allocated the first time it is asked for. */
    public function reference(Objects $objects): Reference
    {
        return $this->reference ??= $objects->allocate();
    }
}
