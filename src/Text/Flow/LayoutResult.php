<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Flow\FlowResult;

/**
 * The lines a layout placed in a box, how deep they reach, where the flow continues, and why the
 * layout stopped.
 *
 * @internal
 */
final readonly class LayoutResult
{
    /** @param list<PlacedLine> $lines */
    public function __construct(
        public array $lines,
        public float $height,
        public FlowCursor $cursor,
        public FlowResult $result,
    ) {}
}
