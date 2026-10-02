<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\FontInfo;
use Php2Pdf\Font\FontStyle;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
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
        self::assertFalse($registry->has('Futura'));
        self::assertTrue($registry->has('Helvetica'), 'The standard 14 fonts are always available.');

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
