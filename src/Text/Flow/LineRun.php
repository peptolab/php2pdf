<?php

declare(strict_types=1);

namespace PhpPdf\Text\Flow;

use PhpPdf\Flow\FlowResult;

/**
 * Lines broken from one paragraph in one box.
 *
 * @internal
 */
final readonly class LineRun
{
    /**
     * @param list<array{0: Line, 1: float}> $lines    lines with their baseline offsets
     * @param int                            $consumed items used by the lines
     * @param ?FlowResult                    $stop     set when a column or page break item was reached
     */
    public function __construct(
        public array $lines,
        public int $consumed,
        public ?FlowResult $stop,
        public float $y,
    ) {}
}
