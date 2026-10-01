<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

/** @internal */
enum ItemKind
{
    /** An unbreakable piece of text. */
    case Box;

    /** Inter-word space: a break opportunity whose width can stretch when justifying. */
    case Glue;

    /** A break opportunity without width, carrying a hyphen when the line breaks here. */
    case Penalty;

    /** Artwork set in the line like a word of its width. */
    case Inline;

    case Tab;
    case LineBreak;
    case ColumnBreak;
    case PageBreak;
}
