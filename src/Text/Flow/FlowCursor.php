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

    /** The start of a flow: one cursor shared by every flow, as a cursor never changes. */
    public static function start(): self
    {
        static $start = new self();

        return $start;
    }
}
