<?php

declare(strict_types=1);

namespace Php2Pdf\Text;

use Php2Pdf\Flow\FlowResult;

/**
 * What the most recent Textflow fit did.
 *
 * @api
 */
final readonly class FitInfo
{
    /**
     * @param float                        $height        the vertical extent used, from the top of the box
     * @param array{0: float, 1: float}|null $firstBaseline canvas coordinates of the first baseline's start
     * @param array{0: float, 1: float}|null $lastBaseline  canvas coordinates of the last baseline's start
     * @param float                        $scale         the copy-fitting scale applied (1.0 when none)
     * @param array{0: float, 1: float}|null $textEnd       canvas coordinates where the last placed line's
     *                                                     text ends, on its baseline, to continue inline
     */
    public function __construct(
        public FlowResult $result,
        public float $height,
        public int $lineCount,
        public ?array $firstBaseline,
        public ?array $lastBaseline,
        public float $scale,
        public ?array $textEnd = null,
    ) {}

    public function overflowed(): bool
    {
        return FlowResult::Done !== $this->result;
    }
}
