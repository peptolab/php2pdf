<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Text\Style\TextStyle;

/**
 * A paragraph as markup or a builder gives it, before it is itemised: the style it overrides,
 * its spans of styled text, and its breaks, each placed before the span at its index (one past
 * the last span for a break at the end).
 *
 * @internal
 */
final readonly class ParagraphSource
{
    /**
     * @param list<array{0: string, 1: TextStyle}>      $spans
     * @param list<array{0: int, 1: ItemKind|Item}>     $breaks
     */
    public function __construct(
        public ?TextStyle $style,
        public array $spans,
        public array $breaks,
    ) {}
}
