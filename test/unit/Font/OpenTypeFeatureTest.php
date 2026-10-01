<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Font;

use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Font\FontInterface;
use PhpPdf\Font\Glyph;
use PhpPdf\Font\GlyphRun;
use PhpPdf\Font\TextOptions;
use PhpPdf\Geometry\Box;
use PhpPdf\Text\Style\TextStyle;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_unique;
use function count;
use function preg_match;

/** OpenType features asked for by tag (#32). */
#[Group('unit')]
final class OpenTypeFeatureTest extends TestCase
{
    private Document $doc;
    private MemoryOutput $out;
    private FontInterface $serif;

    /** @return list<int> */
    private static function ids(GlyphRun $run): array
    {
        return array_map(static fn(Glyph $g): int => $g->id, $run->glyphs);
    }

    #[Test]
    public function featuresApplyAlongsideLigaturesAndSurviveSpacing(): void
    {
        $plain  = self::ids($this->serif->shape('office 2'));
        $spaced = self::ids($this->serif->shape('office 2', new TextOptions(
            tracking: 20.0,
            features: ['onum'],
        )));
        $oldstyle = self::ids($this->serif->shape('office 2', new TextOptions(features: ['onum'])));

        self::assertSame([6, 8, 6], [count($oldstyle), count($spaced), count($plain)]);
        self::assertNotSame($plain[count($plain) - 1], $oldstyle[count($oldstyle) - 1]);
    }

    /** Source Serif's figures are tabular by default; pnum makes them proportional, tnum keeps one width. */
    #[Test]
    public function figureSpacingFollowsTheFeatures(): void
    {
        $widths = fn(array $features): int => count(array_unique(array_map(
            static fn(Glyph $g): int => $g->advance,
            $this->serif->shape('1234567890', new TextOptions(features: $features))->glyphs,
        )));

        self::assertSame([1, true, 1], [$widths([]), $widths(['pnum']) > 1, $widths(['tnum'])]);
    }

    #[Test]
    public function textStylesPassTheirFeaturesToTheFlow(): void
    {
        $flow = $this->doc->textflow('Small caps', new TextStyle(
            font: $this->serif,
            size: 10.0,
            features: ['smcp'],
        ));
        $this->doc->addPage(PageSize::A4)->fitTextflow($flow, new Box(0.0, 0.0, 300.0, 100.0));
        $this->doc->close();

        self::assertSame(
            1,
            preg_match('/<[0-9A-F]{4}> <006D>/', $this->out->contents()),
            'The small-cap m copies as m.',
        );
    }

    protected function setUp(): void
    {
        $this->out   = new MemoryOutput();
        $this->doc   = new Document($this->out, new DocumentOptions(compress: false));
        $this->serif = $this->doc->fonts()->load(PdfTools::assetPath('fonts/SourceSerif4-Regular.ttf'));
    }
}
