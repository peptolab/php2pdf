<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Flow;

use Php2Pdf\Font\FontInterface;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Text\Flow\TabRuler;
use Php2Pdf\Text\Style\TabStop;
use Php2Pdf\Text\Style\TextStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class TabRulerTest extends TestCase
{
    /** @return array<string, array{0: list<TabStop>, 1: float, 2: float}> */
    public static function defaultTabProvider(): array
    {
        return [
            'without stops'      => [[], 10.0, 50.0],
            'past the last stop' => [[TabStop::left(20.0)], 60.0, 100.0],
            'on a default stop'  => [[], 50.0, 100.0],
        ];
    }

    #[Test]
    public function aLeaderWithNoWidthFillsNothing(): void
    {
        $font = $this->createStub(FontInterface::class);
        $font->method('shape')->willReturn(new GlyphRun([new Glyph(1, 0x200B, 0)]));
        $font->method('width')->willReturn(0.0);
        $style = new TextStyle(
            font: $font,
            size: 10.0,
            tabs: [TabStop::left(50.0, "\u{200B}")],
        );

        self::assertNull(TabRuler::of($style)->leader(0.0, 50.0, $style));
    }

    /** @param list<TabStop> $stops */
    #[Test]
    #[DataProvider('defaultTabProvider')]
    public function aTabWithNoStopAfterItGoesToTheNextDefaultStop(array $stops, float $x, float $target): void
    {
        $ruler = TabRuler::of(new TextStyle(
            tabs: $stops,
            defaultTabWidth: 50.0,
        ));

        self::assertSame($target, $ruler->target($x, [], from: 0));
    }
}
