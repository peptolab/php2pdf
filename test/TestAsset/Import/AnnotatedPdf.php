<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset\Import;

use Php2PdfTest\TestAsset\HostileFiles;
use Php2PdfTest\TestAsset\LayeredPdf;

/**
 * A blank 200 pt square page with an annotation of each kind flattening tells apart, each
 * appearance a square of one colour filling its 10 pt box:
 *
 * - red at [20 20 60 60], printing: drawn;
 * - blue at [80 20 120 60], not printing: left out;
 * - green at [140 20 180 60], printing but hidden: left out;
 * - magenta at [20 80 60 120], in the state /On of two (/Off is blue): drawn;
 * - yellow at [80 80 120 160], its appearance 20 by 10 pt turned a quarter by its own matrix: drawn;
 * - cyan at [20 140 60 180], printing, in a layer off by default: marked with the layer;
 * - a link to https://example.com/ at [140 80 180 120], and one to the page itself at
 *   [140 140 180 180], neither drawn.
 *
 * Object 20 is an appearance with no area. Extra annotations are numbered from 21 and added to
 * the page's, and extra resources added to its own; $annots replaces its /Annots.
 */
final class AnnotatedPdf
{
    public const string URI = 'https://example.com/';

    /** @param list<string> $extra annotations */
    public static function pdf(array $extra = [], string $resources = '', ?string $annots = null): string
    {
        $more = '';
        foreach ($extra as $i => $_) {
            $more .= ' ' . (21 + $i) . ' 0 R';
        }
        $square = static fn(string $colour): string => LayeredPdf::stream(
            "{$colour} 0 0 10 10 re f",
            '/Type /XObject /Subtype /Form /BBox [0 0 10 10]',
        );

        return HostileFiles::pdf([
            '<< /Type /Catalog /Pages 2 0 R /OCProperties << /OCGs [12 0 R] /D << /OFF [12 0 R] >> >> >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << {$resources} >> /Contents 4 0 R"
                . ' /Annots '
                . ($annots ?? "[5 0 R 6 0 R 7 0 R 8 0 R 9 0 R 10 0 R 11 0 R 13 0 R{$more}]")
                . ' >>',
            LayeredPdf::stream(''),
            '<< /Type /Annot /Subtype /Square /F 4 /Rect [20 20 60 60] /AP << /N 14 0 R >> >>',
            '<< /Type /Annot /Subtype /Square /F 0 /Rect [80 20 120 60] /AP << /N 15 0 R >> >>',
            '<< /Type /Annot /Subtype /Square /F 6 /Rect [140 20 180 60] /AP << /N 16 0 R >> >>',
            '<< /Type /Annot /Subtype /Widget /F 4 /Rect [60 120 20 80] /AS /On'
                . ' /AP << /N << /On 17 0 R /Off 15 0 R >> >> >>',
            '<< /Type /Annot /Subtype /Square /F 4 /Rect [80 80 120 160] /AP << /N 18 0 R >> >>',
            '<< /Type /Annot /Subtype /Link /Rect [140 80 180 120] /A << /S /URI /URI (' . self::URI . ') >> >>',
            '<< /Type /Annot /Subtype /Link /Rect [140 140 180 180] /Dest [3 0 R /Fit] >>',
            '<< /Type /OCG /Name (Off) >>',
            '<< /Type /Annot /Subtype /Square /F 4 /Rect [20 140 60 180] /OC 12 0 R /AP << /N 19 0 R >> >>',
            $square('1 0 0 rg'),
            $square('0 0 1 rg'),
            $square('0 1 0 rg'),
            $square('1 0 1 rg'),
            LayeredPdf::stream(
                '1 1 0 rg 0 0 20 10 re f',
                '/Type /XObject /Subtype /Form /BBox [0 0 20 10] /Matrix [0 1 -1 0 0 0]',
            ),
            $square('0 1 1 rg'),
            LayeredPdf::stream('0 g 0 0 10 10 re f', '/Type /XObject /Subtype /Form /BBox [0 0 0 10]'),
            ...$extra,
        ]);
    }
}
