<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

/**
 * A position in a flow's paragraphs: the paragraph, the item in it, and the characters of that
 * item already placed when a word too wide for any line was split there.
 *
 * @internal
 */
final readonly class FlowCursor
{
    public function __construct(
        public int $paragraph = 0,
        public int $item = 0,
        public int $carry = 0,
    ) {}

    /** Whether the position is the start of its paragraph. */
    public function atParagraphStart(): bool
    {
        return 0 === $this->item && 0 === $this->carry;
    }
}
