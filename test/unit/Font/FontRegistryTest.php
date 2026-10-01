<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Font;

use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Document;
use PhpPdf\Exception\IoException;
use PhpPdf\Exception\PdfException;
use PhpPdf\Font\FontInfo;
use PhpPdf\Font\FontStyle;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FontRegistryTest extends TestCase
{
    #[Test]
    public function findsFontsByNameAndGroupsFamilies(): void
    {
        $doc      = new Document(new MemoryOutput());
        $registry = $doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'));

        self::assertTrue($registry->has('DejaVuSans-Bold'));
        self::assertTrue($registry->has('dejavu sans bold'));
        self::assertTrue($registry->has('DejaVu Sans'));
        self::assertFalse($registry->has('Helvetica'));

        $bold = $registry->find('DejaVu Sans Bold');
        self::assertSame('DejaVuSans-Bold', $bold->name());
        self::assertSame($bold, $registry->load('DejaVuSans-Bold'), 'The same file embeds once.');

        $family = $registry->family('dejavu sans');
        self::assertSame('DejaVu Sans', $family->name);
        self::assertTrue($family->has(FontStyle::Bold));
        self::assertFalse($family->has(FontStyle::Italic));
        self::assertSame('DejaVuSans', $family->regular()->name());
        self::assertSame($bold, $family->bold());
    }

    #[Test]
    public function peeksNamesAndStyleWithoutFullParse(): void
    {
        $infos = FontInfo::peek(PdfTools::assetPath('fonts/DejaVuSans-Bold.ttf'));

        self::assertCount(1, $infos);
        self::assertSame('DejaVu Sans', $infos[0]->family);
        self::assertSame('Bold', $infos[0]->subfamily);
        self::assertSame('DejaVuSans-Bold', $infos[0]->postScriptName);
        self::assertSame(FontStyle::Bold, $infos[0]->style);
    }

    #[Test]
    public function reportsMissingFiles(): void
    {
        $doc = new Document(new MemoryOutput());

        $this->expectException(IoException::class);
        $doc->fonts()->load('/nowhere/font.ttf');
    }

    #[Test]
    public function reportsMissingFonts(): void
    {
        $doc = new Document(new MemoryOutput());

        $this->expectException(PdfException::class);
        $doc->fonts()->find('Nope');
    }
}
