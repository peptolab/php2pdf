<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use function is_int;

/**
 * An entry of the document outline (the bookmarks panel), with optional children.
 *
 * @api
 */
final class Bookmark
{
    /** @var list<Bookmark> */
    private array $children = [];

    /** @internal */
    public function __construct(
        public readonly string $title,
        public readonly ?Destination $destination,
        public readonly ?Bookmark $parent,
        public readonly bool $open,
    ) {}

    /** Adds a child entry; a page number is shorthand for Destination::page(). */
    public function add(string $title, Destination|int|null $target = null, bool $open = true): self
    {
        $destination      = is_int($target) ? Destination::page($target) : $target;
        $child            = new self($title, $destination, $this, $open);
        $this->children[] = $child;

        return $child;
    }

    /** @return list<Bookmark> */
    public function children(): array
    {
        return $this->children;
    }

    /** The number of descendants, for the outline's /Count. */
    public function descendantCount(): int
    {
        $count = 0;
        foreach ($this->children as $child) {
            $count += 1 + $child->descendantCount();
        }

        return $count;
    }
}
