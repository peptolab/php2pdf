<?php

declare(strict_types=1);

namespace Php2Pdf\Structure;

use Php2Pdf\Core\Object\Reference;

use function array_key_last;

/**
 * The marked content of one page's content stream, or of a form drawn on a page: each MCID with
 * the element whose content it is, for the parent tree under the stream's /StructParents key.
 * A page's own content numbers its MCIDs as it marks them; a form's come from its content.
 *
 * @internal
 */
final class PageMarks
{
    /** @var array<int, StructureElement> the element of each MCID, by MCID */
    private array $elements = [];

    /** @param Reference|null $form the form whose content is marked, drawn on the page; null for the page's own */
    public function __construct(
        public readonly Reference $page,
        public readonly int $key,
        public readonly ?Reference $form = null,
    ) {}

    /** Gives an MCID the stream's content already has to the content of an element. */
    public function claim(StructureElement $element, int $mcid): void
    {
        $this->elements[$mcid] = $element;
        $element->addContent($this->page, $mcid, $this->form);
    }

    /**
     * The element of each MCID from 0 to the highest given, null for those given to none.
     *
     * @return list<StructureElement|null>
     */
    public function elements(): array
    {
        $last = array_key_last($this->elements) ?? -1;
        $list = [];
        for ($mcid = 0; $mcid <= $last; ++$mcid) {
            $list[] = $this->elements[$mcid] ?? null;
        }

        return $list;
    }

    /** Gives the next MCID to content of an element. */
    public function mark(StructureElement $element): int
    {
        $last = array_key_last($this->elements);
        $mcid = null === $last ? 0 : $last + 1;
        $this->claim($element, $mcid);

        return $mcid;
    }
}
