<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Import\PdfFile;
use Php2PdfTest\TestAsset\HostileFiles;
use Php2PdfTest\TestAsset\LayeredPdf;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The fonts an imported page uses without their programs, wherever they are drawn from. */
#[Group('unit')]
final class FontScannerTest extends TestCase
{
    /**
     * A page whose resources hold an embedded TrueType font (F1), a Type 0 font whose descendant
     * is embedded (F2), Helvetica (F3), a Type 3 font drawing with Courier (F4), Times twice (F5,
     * and in two forms that share their resources), a font without a name in a pattern, and an
     * entry that is no font (F6).
     */
    private static function page(): string
    {
        return HostileFiles::pdf([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Contents 4 0 R /Resources << /Font << /F1 5 0 R'
                . ' /F2 7 0 R /F3 9 0 R /F4 10 0 R /F5 12 0 R /F6 (none) >> /XObject << /X1 13 0 R /X2 (none) /X3 17 0 R >>'
                . ' /Pattern << /P1 14 0 R >> >> >>',
            LayeredPdf::stream(''),
            '<< /Type /Font /Subtype /TrueType /BaseFont /Embedded /FontDescriptor 6 0 R >>',
            '<< /Type /FontDescriptor /FontFile2 4 0 R >>',
            '<< /Type /Font /Subtype /Type0 /BaseFont /Composite /DescendantFonts [8 0 R] >>',
            '<< /Type /Font /Subtype /CIDFontType2 /FontDescriptor 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type3 /Resources << /Font << /C 11 0 R >> >> >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman >>',
            LayeredPdf::stream('', '/Type /XObject /Subtype /Form /Resources 16 0 R'),
            LayeredPdf::stream('', '/PatternType 1 /Resources << /Font << /S 15 0 R >> >>'),
            '<< /Type /Font /Subtype /Type1 >>',
            '<< /Font << /T 12 0 R >> >>',
            LayeredPdf::stream('', '/Type /XObject /Subtype /Form /Resources 16 0 R'),
        ]);
    }

    #[Test]
    public function aPageWithoutResourcesHasNoFonts(): void
    {
        self::assertSame([], PdfFile::fromData(HostileFiles::pages(1))->page(1)->unembeddedFonts());
    }

    #[Test]
    public function fontsWithoutProgramsAreFoundWhereverTheyAreDrawnFrom(): void
    {
        self::assertSame(
            ['Helvetica', 'Courier', 'Times-Roman', 'unnamed'],
            PdfFile::fromData(self::page())->page(1)->unembeddedFonts(),
        );
    }
}
