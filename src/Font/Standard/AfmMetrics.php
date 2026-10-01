<?php

declare(strict_types=1);

namespace PhpPdf\Font\Standard;

use PhpPdf\Font\StandardFont;

/**
 * A standard font's metrics, in thousandths of an em, from the data files generated from Adobe's
 * Core 14 AFM files by tools/generate-standard-fonts.php. Loaded once per process.
 *
 * @internal
 */
final readonly class AfmMetrics
{
    /**
     * @param array{0: int, 1: int, 2: int, 3: int} $bbox
     * @param array<int, int>                       $codes   code point => code
     * @param array<int, int>                       $widths  code => width
     * @param array<int, array<int, int>>           $kerning left code => right code => adjustment
     */
    public function __construct(
        public ?string $encoding,
        public float $italicAngle,
        public bool $fixedPitch,
        public array $bbox,
        public int $ascender,
        public int $descender,
        public int $capHeight,
        public int $xHeight,
        public int $underlinePosition,
        public int $underlineThickness,
        public int $stemV,
        public array $codes,
        public array $widths,
        public array $kerning,
    ) {}

    public static function of(StandardFont $font): self
    {
        /** @var array<string, self> $loaded */
        static $loaded = [];
        $cached = $loaded[$font->value] ?? null;
        if (null !== $cached) {
            return $cached;
        }
        /**
         * @var array{
         *     encoding: string|null,
         *     italicAngle: float,
         *     fixedPitch: bool,
         *     bbox: array{0: int, 1: int, 2: int, 3: int},
         *     ascender: int,
         *     descender: int,
         *     capHeight: int,
         *     xHeight: int,
         *     underlinePosition: int,
         *     underlineThickness: int,
         *     stemV: int,
         *     codes: array<int, int>,
         *     widths: array<int, int>,
         *     kerning: array<int, array<int, int>>,
         * } $data
         */
        $data = require __DIR__ . "/data/{$font->value}.php";

        return $loaded[$font->value] = new self(
            $data['encoding'],
            $data['italicAngle'],
            $data['fixedPitch'],
            $data['bbox'],
            $data['ascender'],
            $data['descender'],
            $data['capHeight'],
            $data['xHeight'],
            $data['underlinePosition'],
            $data['underlineThickness'],
            $data['stemV'],
            $data['codes'],
            $data['widths'],
            $data['kerning'],
        );
    }
}
