<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Svg;

use PhpPdf\Svg\MarkerVertices;
use PhpPdf\Svg\PathParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Where markers go on a path and the angles they orient to (#37). */
#[Group('unit')]
final class MarkerVerticesTest extends TestCase
{
    /** @return array<string, array{0: string, 1: list<array{0: float, 1: float, 2: float}>}> */
    public static function pathProvider(): array
    {
        return [
            'corners bisect'                    => [
                'M0 0 L10 0 L10 10',
                [[0.0, 0.0, 0.0], [10.0, 0.0, 45.0], [10.0, 10.0, 90.0]],
            ],
            'a closed start bisects'            => [
                'M0 0 L10 0 L10 10 L0 10 Z',
                [[0.0, 0.0, -45.0], [10.0, 0.0, 45.0], [10.0, 10.0, 135.0], [0.0, 10.0, -135.0], [0.0, 0.0, -45.0]],
            ],
            'an arc is one segment'             => ['M0 0 A5 5 0 0 1 10 0', [[0.0, 0.0, -90.0], [10.0, 0.0, 90.0]]],
            'zero length follows'               => [
                'M0 0 L10 0 L10 0',
                [[0.0, 0.0, 0.0], [10.0, 0.0, 0.0], [10.0, 0.0, 0.0]],
            ],
            'a leading zero length looks ahead' => [
                'M0 0 L0 0 L0 10',
                [[0.0, 0.0, 90.0], [0.0, 0.0, 90.0], [0.0, 10.0, 90.0]],
            ],
            'coincident control points'         => ['M0 0 C0 0 10 10 10 0', [[0.0, 0.0, 45.0], [10.0, 0.0, -90.0]]],
            'after closepath no new vertex'     => [
                'M0 0 L10 0 Z L0 10',
                [[0.0, 0.0, 90.0], [10.0, 0.0, 90.0], [0.0, 0.0, 90.0], [0.0, 10.0, 90.0]],
            ],
            'a reversal turns a quarter'        => [
                'M0 0 L10 0 L0 0',
                [[0.0, 0.0, 0.0], [10.0, 0.0, 90.0], [0.0, 0.0, 180.0]],
            ],
            'subpaths each start'               => [
                'M0 0 L10 0 M0 10 L0 20',
                [[0.0, 0.0, 0.0], [10.0, 0.0, 0.0], [0.0, 10.0, 90.0], [0.0, 20.0, 90.0]],
            ],
            'a lone moveto'                     => ['M3 4', [[3.0, 4.0, 0.0]]],
        ];
    }

    /** @param list<array{0: float, 1: float, 2: float}> $expected */
    #[Test]
    #[DataProvider('pathProvider')]
    public function verticesCarryTheDirectionOfThePath(string $d, array $expected): void
    {
        self::assertEqualsWithDelta($expected, MarkerVertices::of(PathParser::parse($d)), 1.0e-6);
    }
}
