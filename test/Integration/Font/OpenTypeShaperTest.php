<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Font\Font;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2Pdf\Font\TextDirection;
use Php2Pdf\Font\TextOptions;
use Php2PdfTest\TestAsset\Font\SfntTables;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_reverse;
use function file_get_contents;

/**
 * Shaping through the engine with fonts parsed afresh, so no run is taken from the shaped-run
 * cache a font shares between documents.
 */
#[Group('integration')]
final class OpenTypeShaperTest extends TestCase
{
    /** @param array<string, string|null> $tables table tag => new bytes, or null to drop the table */
    private static function dejaVu(array $tables = []): Font
    {
        $data = (string) file_get_contents(PdfTools::assetPath('fonts/DejaVuSans.ttf'));

        return (new Document(new MemoryOutput()))->fonts()
            ->embed(OpenTypeFont::fromData([] === $tables ? $data : SfntTables::replace($data, $tables)));
    }

    /** @return list<int> */
    private static function ids(Font $font, string $text, TextDirection $direction): array
    {
        return array_map(
            static fn(Glyph $glyph): int => $glyph->id,
            $font->shape($text, new TextOptions(direction: $direction))->glyphs,
        );
    }

    #[Test]
    public function aFontWithoutPositioningIsKernedFromItsKernTable(): void
    {
        $first = self::dejaVu(['GPOS' => null])->shape('AV')->glyphs[0] ?? null;

        self::assertEqualsWithDelta((-131 * 1000) / 2048, $first?->kerning, 0.001);
    }

    #[Test]
    public function arabicSetLeftToRightTakesTheSameJoiningFormsAsRightToLeft(): void
    {
        $font = self::dejaVu();

        self::assertSame(
            array_reverse(self::ids($font, "\u{0628}\u{0628}\u{0628}", TextDirection::RightToLeft)),
            self::ids($font, "\u{0628}\u{0628}\u{0628}", TextDirection::LeftToRight),
        );
    }
}
