<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Font\StandardFont;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Table\Column;
use Php2Pdf\Table\Table;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Textflow;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class StandardFontTest extends TestCase
{
    #[Test]
    public function standardFontsProduceAValidFileWithExtractableText(): void
    {
        $path  = PdfTools::outputDir() . '/standard-fonts.pdf';
        $doc   = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $fonts = $doc->fonts();
        $page  = $doc->addPage(PageSize::A4);
        $c     = $page->canvas();
        $y     = 780.0;
        foreach (StandardFont::cases() as $font) {
            $text = match ($font) {
                StandardFont::Symbol       => 'αβγ ∑ ∞',
                StandardFont::ZapfDingbats => '✁✂✃',
                default                    => "{$font->value}: Grüße — 12 € “quoted”",
            };
            $c->drawText($text, $fonts->standard($font), 14.0, 50.0, $y);
            $y -= 22.0;
        }
        $style = new TextStyle(
            font: 'Helvetica',
            bold: 'Helvetica-Bold',
            size: 10.0,
        );
        $page->fitTextflow(
            Textflow::fromMarkup($doc, 'Helvetica in a <b>Textflow</b> with kerning: AVATAR To Wave.', $style),
            new Box(50.0, 380.0, 400.0, 40.0),
        );
        $table = new Table([Column::auto(), Column::pt(80.0)], null, $style);
        $table->addRow(['Consultation', '€ 120.00']);
        $page->fitTable($table, new Box(50.0, 300.0, 400.0, 60.0));
        $doc->close();

        PdfTools::assertValid($path);
        if (! PdfTools::hasPoppler()) {
            self::markTestSkipped('poppler is not installed; text extraction not checked.');
        }
        $text = PdfTools::text($path);
        self::assertStringContainsString('Helvetica-Bold: Grüße — 12 € “quoted”', $text);
    }
}
