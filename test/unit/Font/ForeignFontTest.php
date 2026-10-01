<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\TextOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Only the library's own fonts can draw text; the public interface is for measuring (#28). */
#[Group('unit')]
final class ForeignFontTest extends TestCase
{
    #[Test]
    public function drawingWithAFontFromElsewhereIsRefused(): void
    {
        $font = new class implements FontInterface {
            public function advance(int $glyphId): int
            {
                return 500;
            }

            public function ascender(float $size): float
            {
                return $size * 0.8;
            }

            public function capHeight(float $size): float
            {
                return $size * 0.7;
            }

            public function descender(float $size): float
            {
                return $size * -0.2;
            }

            public function hasGlyph(int $codePoint): bool
            {
                return true;
            }

            public function lineGap(float $size): float
            {
                return 0.0;
            }

            public function name(): string
            {
                return 'Foreign';
            }

            public function shape(string $text, ?TextOptions $options = null): GlyphRun
            {
                return new GlyphRun([new Glyph(1, 65, 500)]);
            }

            public function strikeoutPosition(float $size): float
            {
                return $size * 0.3;
            }

            public function strikeoutSize(float $size): float
            {
                return $size * 0.05;
            }

            public function underlinePosition(float $size): float
            {
                return $size * -0.1;
            }

            public function underlineThickness(float $size): float
            {
                return $size * 0.05;
            }

            public function width(string|GlyphRun $text, float $size, ?TextOptions $options = null): float
            {
                return $size * 0.5;
            }

            public function xHeight(float $size): float
            {
                return $size * 0.5;
            }
        };
        $doc = new Document(new MemoryOutput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Text can only be drawn with fonts from the document's FontRegistry");
        $doc->addPage(PageSize::A4)->canvas()->drawText('A', $font, 10.0, 0.0, 0.0);
    }
}
