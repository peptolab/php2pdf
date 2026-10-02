<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

/**
 * A place the paragraph composer may break a line, with the cheapest way to reach it: the line
 * it ends, how tightly that line is set, the demerits so far, and the break before it.
 *
 * @internal
 */
final readonly class Breakpoint
{
    /** @param int $fitness 0 very loose, 1 loose, 2 decent, 3 tight */
    public function __construct(
        public int $position,
        public int $line,
        public int $fitness,
        public float $demerits,
        public bool $hyphen,
        public ?self $previous,
    ) {}
}
