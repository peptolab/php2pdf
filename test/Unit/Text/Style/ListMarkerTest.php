<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Style;

use Php2Pdf\Text\Style\ListMarker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The markers of list items (#35). */
#[Group('unit')]
final class ListMarkerTest extends TestCase
{
    /** @return array<string, array{0: ListMarker, 1: int, 2: string}> */
    public static function labelProvider(): array
    {
        return [
            'disc'                      => [ListMarker::Disc, 3, "\u{2022}"],
            'circle'                    => [ListMarker::Circle, 3, "\u{25E6}"],
            'square'                    => [ListMarker::Square, 3, "\u{25AA}"],
            'dash'                      => [ListMarker::Dash, 3, "\u{2013}"],
            'decimal'                   => [ListMarker::Decimal, 12, '12.'],
            'decimal from zero'         => [ListMarker::Decimal, 0, '0.'],
            'a letter'                  => [ListMarker::LowerAlpha, 1, 'a.'],
            'the last letter'           => [ListMarker::LowerAlpha, 26, 'z.'],
            'two letters'               => [ListMarker::LowerAlpha, 27, 'aa.'],
            'two letters, last'         => [ListMarker::LowerAlpha, 702, 'zz.'],
            'three letters'             => [ListMarker::UpperAlpha, 703, 'AAA.'],
            'letters cannot count zero' => [ListMarker::UpperAlpha, 0, '0.'],
            'roman'                     => [ListMarker::LowerRoman, 1994, 'mcmxciv.'],
            'roman subtractives'        => [ListMarker::UpperRoman, 444, 'CDXLIV.'],
            'roman to its limit'        => [ListMarker::UpperRoman, 3999, 'MMMCMXCIX.'],
            'roman past its limit'      => [ListMarker::LowerRoman, 4000, '4000.'],
            'roman below one'           => [ListMarker::UpperRoman, -2, '-2.'],
        ];
    }

    /** @return array<string, array{0: ListMarker, 1: bool, 2: string}> */
    public static function markerProvider(): array
    {
        return [
            'disc'        => [ListMarker::Disc, false, 'Disc'],
            'circle'      => [ListMarker::Circle, false, 'Circle'],
            'square'      => [ListMarker::Square, false, 'Square'],
            'dash'        => [ListMarker::Dash, false, 'None'],
            'decimal'     => [ListMarker::Decimal, true, 'Decimal'],
            'lower alpha' => [ListMarker::LowerAlpha, true, 'LowerAlpha'],
            'upper alpha' => [ListMarker::UpperAlpha, true, 'UpperAlpha'],
            'lower roman' => [ListMarker::LowerRoman, true, 'LowerRoman'],
            'upper roman' => [ListMarker::UpperRoman, true, 'UpperRoman'],
        ];
    }

    #[Test]
    #[DataProvider('markerProvider')]
    public function aMarkerSaysWhetherItCountsAndHowTaggedPdfNamesIt(
        ListMarker $marker,
        bool $ordered,
        string $numbering,
    ): void {
        self::assertSame([$ordered, $numbering], [$marker->isOrdered(), $marker->numbering()]);
    }

    #[Test]
    #[DataProvider('labelProvider')]
    public function anItemIsLabelledByItsNumberInTheMarkersStyle(ListMarker $marker, int $number, string $label): void
    {
        self::assertSame($label, $marker->label($number));
    }

    #[Test]
    public function bulletsChangeWithDepthAndComeRound(): void
    {
        self::assertSame(
            [ListMarker::Disc, ListMarker::Circle, ListMarker::Square, ListMarker::Disc],
            [ListMarker::bullet(1), ListMarker::bullet(2), ListMarker::bullet(3), ListMarker::bullet(4)],
        );
    }
}
