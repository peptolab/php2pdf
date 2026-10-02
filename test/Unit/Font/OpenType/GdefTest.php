<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType;

use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Font\OpenType\Gdef;
use Php2PdfTest\TestAsset\GsubBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Glyph definitions, read from GDEF or synthesised from the cmap (#33). */
#[Group('unit')]
final class GdefTest extends TestCase
{
    #[Test]
    public function aFontsClassesAreRead(): void
    {
        $gdef = new Gdef(new BinaryReader(GsubBuilder::gdef([1 => 1, 2 => 2, 3 => 3], [3 => 2], [[3]])), [0x0301 => 4]);

        self::assertSame(
            [1, 2, 3, 0, 2, true],
            [
                $gdef->glyphClass(1),
                $gdef->glyphClass(2),
                $gdef->glyphClass(3),
                $gdef->glyphClass(4),
                $gdef->markAttachClass(3),
                $gdef->inMarkSet(0, 3),
            ],
        );
    }

    #[Test]
    public function aGlyphForSeveralCharactersTakesTheClassOfTheFirst(): void
    {
        $gdef = new Gdef(null, [0x61 => 7, 0x0301 => 7]);

        self::assertSame(Gdef::BASE, $gdef->glyphClass(7));
    }

    #[Test]
    public function withoutClassesMarksAndBasesComeFromTheCmap(): void
    {
        $gdef = new Gdef(null, [0x61 => 8, 0x0301 => 7, 0x2DE0 => 7]);

        self::assertSame([Gdef::MARK, Gdef::BASE, 0], [
            $gdef->glyphClass(7),
            $gdef->glyphClass(8),
            $gdef->glyphClass(9),
        ]);
    }
}
