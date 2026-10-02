<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType;

use Php2Pdf\Font\OpenType\BinaryReader;
use Php2Pdf\Font\OpenType\Gdef;
use Php2PdfTest\Support\GsubBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Glyph definitions, read from GDEF or learnt from characters (#33). */
#[Group('unit')]
final class GdefTest extends TestCase
{
    #[Test]
    public function aFontsClassesAreRead(): void
    {
        $gdef = new Gdef(new BinaryReader(GsubBuilder::gdef([1 => 1, 2 => 2, 3 => 3], [3 => 2], [[3]])));
        $gdef->learn(4, 0x0301);

        self::assertSame(
            [1, 2, 3, 0, 2, true, false],
            [
                $gdef->glyphClass(1),
                $gdef->glyphClass(2),
                $gdef->glyphClass(3),
                $gdef->glyphClass(4),
                $gdef->markAttachClass(3),
                $gdef->inMarkSet(0, 3),
                $gdef->isSynthetic(),
            ],
        );
    }

    #[Test]
    public function withoutClassesMarksAndBasesAreLearntFromCharacters(): void
    {
        $gdef = new Gdef(null);
        $gdef->learn(7, 0x0301);
        $gdef->learn(8, 0x61);
        $gdef->learn(7, 0x61);

        self::assertSame([true, Gdef::MARK, Gdef::BASE], [
            $gdef->isSynthetic(),
            $gdef->glyphClass(7),
            $gdef->glyphClass(8),
        ]);
    }
}
