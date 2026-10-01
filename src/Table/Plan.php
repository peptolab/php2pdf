<?php

declare(strict_types=1);

namespace Php2Pdf\Table;

use Php2Pdf\Flow\FlowResult;

/**
 * The rows a fit will draw, top to bottom, with their heights.
 *
 * @internal
 */
final class Plan
{
    /** @var list<array{0: Grid, 1: int, 2: float, 3: ?float}> grid, row, height, height limit when the row is cut */
    public array $rows = [];

    /** @var array<int, float> */
    public array $headerHeights = [];

    /** @var array<int, float> */
    public array $bodyHeights = [];

    /** @var array<int, float> */
    public array $footerHeights = [];

    public ?int $lastBodyRow = null;

    public FlowResult $result = FlowResult::Done;
}
