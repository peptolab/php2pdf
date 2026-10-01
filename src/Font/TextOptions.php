<?php

declare(strict_types=1);

namespace PhpPdf\Font;

/**
 * Options for showing a line of text. Spacing values are in points, tracking in thousandths of
 * an em, horizontal scaling in percent.
 *
 * @api
 */
final readonly class TextOptions
{
    public function __construct(
        public float $charSpacing = 0.0,
        public float $wordSpacing = 0.0,
        public float $tracking = 0.0,
        public float $horizontalScale = 100.0,
        public float $rise = 0.0,
        public TextRenderMode $renderMode = TextRenderMode::Fill,
        public bool $kerning = true,
    ) {}

    public static function default(): self
    {
        static $default = null;

        return $default ??= new self();
    }
}
