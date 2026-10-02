<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Font\GlyphCheck;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * A font file is parsed once and shared by the documents that load it, and so is what it shapes:
 * the second document reuses the first's runs. What a document reports about its text (missing
 * glyphs) is still reported in each.
 */
#[Group('integration')]
final class SharedShapingTest extends TestCase
{
    /** Text with a character DejaVu Sans has no glyph for. */
    private const string TEXT = "Snowman \u{E000} here";

    private static function document(): Document
    {
        return new Document(new MemoryOutput(), new DocumentOptions(glyphCheck: GlyphCheck::Warn));
    }

    #[Test]
    public function documentsShareTheRunsTheyShape(): void
    {
        $first  = self::document()->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $second = self::document()->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));

        self::assertSame($first->shape('Shared text'), $second->shape('Shared text'));
    }

    #[Test]
    public function eachDocumentReportsTheGlyphsItsFontLacks(): void
    {
        $warnings = [];
        foreach ([self::document(), self::document()] as $document) {
            $document->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'))->shape(self::TEXT);
            $warnings[] = count($document->fonts()->warnings());
        }

        self::assertSame([1, 1], $warnings);
    }

    #[Override]
    protected function setUp(): void
    {
        OpenTypeFont::clearCache();
    }

    #[Override]
    protected function tearDown(): void
    {
        OpenTypeFont::clearCache();
    }
}
