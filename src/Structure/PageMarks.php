<?php

declare(strict_types=1);

namespace Php2Pdf\Structure;

use Php2Pdf\Core\Object\Reference;

use function count;

/**
 * The marked content of one page: each MCID it gives, in order, with the element whose content
 * it is, for the parent tree under the page's /StructParents key.
 *
 * @internal
 */
final class PageMarks
{
    /** @var list<StructureElement> the element of each MCID, by MCID */
    private array $elements = [];

    public function __construct(
        public readonly Reference $page,
        public readonly int $key,
    ) {}

    /** @return list<StructureElement> */
    public function elements(): array
    {
        return $this->elements;
    }

    /** Gives the next MCID to content of an element. */
    public function mark(StructureElement $element): int
    {
        $mcid             = count($this->elements);
        $this->elements[] = $element;
        $element->addContent($this->page, $mcid);

        return $mcid;
    }
}
