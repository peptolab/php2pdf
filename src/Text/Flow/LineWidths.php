<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Text\Style\ParagraphFormat;
use Php2Pdf\Text\Style\TextStyle;

use function max;

/**
 * Where each line of a paragraph starts and how wide it is, by its number in the paragraph: the
 * frame's width less the paragraph's indents, the first line's indent as well on line 0. Shapes
 * other than a rectangle (drop caps, text wrapped around artwork) give other answers here.
 *
 * @internal
 */
final readonly class LineWidths
{
    public function __construct(
        private float $frameWidth,
        private TextStyle $style,
    ) {}

    /** The width line $line has for its items. */
    public function available(int $line): float
    {
        return max(1.0, $this->frameWidth - $this->left($line) - ParagraphFormat::of($this->style)->rightIndent);
    }

    /** What the widths depend on beyond the paragraph's own style: the frame's width. */
    public function key(): string
    {
        return (string) $this->frameWidth;
    }

    /** How far line $line starts from the frame's left edge. */
    public function left(int $line): float
    {
        $format = ParagraphFormat::of($this->style);

        return $format->leftIndent + (0 === $line ? $format->firstLineIndent : 0.0);
    }
}
