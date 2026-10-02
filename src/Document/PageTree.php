<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Objects;

use function count;

/**
 * The document's pages: the one being drawn, and those already written, which the page tree
 * lists when the document closes.
 *
 * @internal
 */
final class PageTree
{
    private readonly Reference $reference;

    /** @var list<Reference> */
    private array $written = [];

    private ?Page $current = null;

    public function __construct(
        private readonly Objects $objects,
    ) {
        $this->reference = $objects->allocate();
    }

    /** The number of pages, the one being drawn included. */
    public function count(): int
    {
        return count($this->written) + (null === $this->current ? 0 : 1);
    }

    public function current(): ?Page
    {
        return $this->current;
    }

    /** Finishes and writes the page being drawn, if any. */
    public function finishCurrent(): void
    {
        if (null === $this->current) {
            return;
        }
        $this->written[] = $this->current->finish($this->reference);
        $this->current   = null;
    }

    /** The 1-based number the next page will have. */
    public function nextNumber(): int
    {
        return count($this->written) + 1;
    }

    /** Makes a page the one being drawn; the previous one must be finished. */
    public function open(Page $page): Page
    {
        return $this->current = $page;
    }

    /** @return list<Reference> the written pages, in order */
    public function references(): array
    {
        return $this->written;
    }

    /** Writes the page tree, and returns its reference for the catalog. */
    public function write(): Reference
    {
        $this->objects->write($this->reference, Dictionary::of([
            'Type'  => new Name('Pages'),
            'Kids'  => $this->written,
            'Count' => count($this->written),
        ]));

        return $this->reference;
    }
}
