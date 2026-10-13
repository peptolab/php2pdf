<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Text\Style\ListMarker;

use function count;

/**
 * Where a paragraph sits in lists: the list and item it belongs to at each level of nesting,
 * outermost first, and the label its item's marker shows, which only an item's first paragraph
 * has.
 *
 * @internal
 */
final readonly class ListPosition
{
    /** @param non-empty-list<array{0: int, 1: int, 2: ListMarker}> $path each level's list and item, by number, and the list's marker */
    public function __construct(
        public array $path,
        public ?string $label,
    ) {}

    /** How deeply the paragraph is nested: 1 in a list of its own. */
    public function depth(): int
    {
        return count($this->path);
    }
}
