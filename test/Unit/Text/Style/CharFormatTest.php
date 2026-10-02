<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Style;

use Php2Pdf\Text\Style\CharFormat;
use Php2Pdf\Text\Style\Justification;
use Php2Pdf\Text\Style\Leading;
use Php2Pdf\Text\Style\TextStyle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class CharFormatTest extends TestCase
{
    #[Test]
    public function anUnsetStyleHasTheDefaults(): void
    {
        $format = CharFormat::of(new TextStyle());

        self::assertEqualsWithDelta(
            [10.0, 12.0, 100.0, 0.0, 0.33, 0.2, []],
            [
                $format->size,
                $format->lineHeight,
                $format->horizontalScale,
                $format->rise,
                $format->stretch,
                $format->shrink,
                $format->fallback,
            ],
            1.0e-9,
        );
    }

    #[Test]
    public function aSetStyleKeepsItsValues(): void
    {
        $format = CharFormat::of(new TextStyle(
            font: 'Serif',
            size: 12.0,
            leading: Leading::pt(18.0),
            horizontalScale: 90.0,
            rise: 3.0,
            fallback: ['Sans'],
            justification: new Justification(90.0, 120.0),
        ));

        self::assertEqualsWithDelta(
            [12.0, 18.0, 90.0, 3.0, 0.2, 0.1, ['Sans']],
            [
                $format->size,
                $format->lineHeight,
                $format->horizontalScale,
                $format->rise,
                $format->stretch,
                $format->shrink,
                $format->fallback,
            ],
            1.0e-9,
        );
    }

    #[Test]
    public function stylesResolvingAlikeShareOneFormat(): void
    {
        self::assertSame(
            [true, false],
            [
                CharFormat::of(new TextStyle(size: 9.0)) === CharFormat::of(new TextStyle(size: 9.0)),
                CharFormat::of(new TextStyle(size: 9.0)) === CharFormat::of(new TextStyle(size: 9.5)),
            ],
        );
    }

    #[Test]
    public function theTextOptionsAreMadeOnce(): void
    {
        $style = new TextStyle(charSpacing: 1.5);

        self::assertSame(
            [1.5, true],
            [CharFormat::of($style)->textOptions->charSpacing, CharFormat::of($style) === CharFormat::of($style)],
        );
    }
}
