<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Exception\ParseException;

use function round;
use function strlen;

/**
 * Collects vertical metrics from head, hhea, post and OS/2, measuring cap and x heights from
 * glyph outlines when OS/2 does not carry them.
 *
 * @internal
 */
final readonly class MetricsParser
{
    public function __construct(
        private OpenTypeFont $font,
    ) {}

    /** @throws ParseException */
    public function parse(BinaryReader $head): Metrics
    {
        $upem     = $this->font->unitsPerEm;
        $macStyle = $head->u16(44);
        $hhea     = $this->hhea($upem);
        $post     = $this->post($upem);
        $os2      = $this->os2($upem);

        return new Metrics(
            unitsPerEm: $upem,
            ascender: $hhea['ascender'],
            descender: $hhea['descender'],
            lineGap: $hhea['lineGap'],
            capHeight: $os2['capHeight'] > 0 ? $os2['capHeight'] : $this->measureHeight(0x48, 0.7),
            xHeight: $os2['xHeight'] > 0 ? $os2['xHeight'] : $this->measureHeight(0x78, 0.5),
            underlinePosition: $post['underlinePosition'],
            underlineThickness: $post['underlineThickness'],
            strikeoutPosition: $os2['strikeoutPosition'],
            strikeoutSize: $os2['strikeoutSize'],
            italicAngle: $post['italicAngle'],
            weightClass: $os2['weightClass'],
            bbox: [$head->i16(36), $head->i16(38), $head->i16(40), $head->i16(42)],
            isFixedPitch: $post['isFixedPitch'],
            isItalic: 0 !== ($macStyle & 0x0002),
            isBold: 0 !== ($macStyle & 0x0001),
            isSerif: $os2['isSerif'],
        );
    }

    /**
     * @return array{ascender: int, descender: int, lineGap: int}
     *
     * @throws ParseException
     */
    private function hhea(int $upem): array
    {
        $hhea = $this->font->tableReader('hhea');
        if (null === $hhea) {
            return ['ascender' => (int) round($upem * 0.8), 'descender' => -(int) round($upem * 0.2), 'lineGap' => 0];
        }

        return ['ascender' => $hhea->i16(4), 'descender' => $hhea->i16(6), 'lineGap' => $hhea->i16(8)];
    }

    /**
     * The yMax of a glyph's bounding box, or a fallback fraction of the em when unavailable.
     *
     * @throws ParseException
     */
    private function measureHeight(int $codePoint, float $fallback): int
    {
        $glyph = $this->font->glyphId($codePoint);
        if (0 !== $glyph && ! $this->font->isCff) {
            $data = $this->font->glyphData($glyph);
            if (strlen($data) >= 10) {
                $reader = new BinaryReader($data, what: 'the font data');

                return $reader->i16(8);
            }
        }

        return (int) round($this->font->unitsPerEm * $fallback);
    }

    /**
     * @return array{
     *     weightClass: int, strikeoutPosition: int, strikeoutSize: int, isSerif: bool, capHeight: int, xHeight: int
     * }
     *
     * @throws ParseException
     */
    private function os2(int $upem): array
    {
        $os2 = $this->font->tableReader('OS/2');
        if (null === $os2) {
            return [
                'weightClass'       => 400,
                'strikeoutPosition' => (int) round($upem * 0.25),
                'strikeoutSize'     => (int) round($upem * 0.05),
                'isSerif'           => false,
                'capHeight'         => 0,
                'xHeight'           => 0,
            ];
        }
        $version = $os2->u16(0);
        $panose  = [$os2->u8(32), $os2->u8(33)];

        return [
            'weightClass'       => $os2->u16(4),
            'strikeoutPosition' => $os2->i16(28),
            'strikeoutSize'     => $os2->i16(26),
            'isSerif'           => 2 === $panose[0] && $panose[1] >= 2 && $panose[1] <= 10,
            'capHeight'         => $version >= 2 ? $os2->i16(88) : 0,
            'xHeight'           => $version >= 2 ? $os2->i16(86) : 0,
        ];
    }

    /**
     * @return array{underlinePosition: int, underlineThickness: int, italicAngle: float, isFixedPitch: bool}
     *
     * @throws ParseException
     */
    private function post(int $upem): array
    {
        $post = $this->font->tableReader('post');
        if (null === $post) {
            return [
                'underlinePosition'  => -(int) round($upem * 0.1),
                'underlineThickness' => (int) round($upem * 0.05),
                'italicAngle'        => 0.0,
                'isFixedPitch'       => false,
            ];
        }

        return [
            'underlinePosition'  => $post->i16(8),
            'underlineThickness' => $post->i16(10),
            'italicAngle'        => $post->fixed(4),
            'isFixedPitch'       => 0 !== $post->u32(12),
        ];
    }
}
