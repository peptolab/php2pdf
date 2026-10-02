<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Geometry;

use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Geometry\Path;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function round;

#[Group('unit')]
final class PathTest extends TestCase
{
    /** @return array<string, array{0: float, 1: float, 2: string}> */
    public static function arcProvider(): array
    {
        return [
            'a quarter turn is one cubic'         => [0.0, 90.0, 'MC'],
            'a half turn is two'                  => [0.0, 180.0, 'MCC'],
            'a little over a quarter is two'      => [0.0, 91.0, 'MCC'],
            'clockwise, a half turn is two'       => [180.0, 0.0, 'MCC'],
            'more than a full turn is one turn'   => [0.0, 720.0, 'MCCCC'],
            'a sweep of nothing is still a curve' => [45.0, 45.0, 'MC'],
        ];
    }

    /** @return array<string, array{0: Path, 1: ?Box}> */
    public static function boundsProvider(): array
    {
        return [
            'an empty path'         => [new Path(), null],
            'a rectangle'           => [Path::rect(1.0, 2.0, 3.0, 4.0), new Box(1.0, 2.0, 3.0, 4.0)],
            'an ellipse'            => [Path::ellipse(10.0, 20.0, 5.0, 2.0), new Box(5.0, 18.0, 10.0, 4.0)],
            'a curve past its ends' => [
                new Path([['M', [0.0, 0.0]], ['C', [0.0, 10.0, 10.0, 10.0, 10.0, 0.0]]]),
                new Box(0.0, 0.0, 10.0, 7.5),
            ],
            'a closed triangle'     => [Path::polygon([0.0, 0.0, 4.0, 0.0, 2.0, 3.0]), new Box(0.0, 0.0, 4.0, 3.0)],
        ];
    }

    private static function ops(Path $path): string
    {
        $ops = '';
        foreach ($path->commands as [$op]) {
            $ops .= $op;
        }

        return $ops;
    }

    /** @return list<float> the coordinates of a path's command */
    private static function points(Path $path, int $index): array
    {
        return ($path->commands[$index] ?? ['Z', []])[1];
    }

    /**
     * @param Box|null $box
     *
     * @return list<float>|null
     */
    private static function rounded(?Box $box): ?array
    {
        if (null === $box) {
            return null;
        }

        return [
            round($box->x, precision: 6),
            round($box->y, precision: 6),
            round($box->width, precision: 6),
            round($box->height, precision: 6),
        ];
    }

    #[Test]
    public function aJoinedPathContinuesWithALine(): void
    {
        self::assertSame('LC', self::ops(Path::arc(0.0, 0.0, 1.0, 0.0, 90.0)->joined()));
    }

    #[Test]
    #[DataProvider('arcProvider')]
    public function anArcHasACubicForEachQuarterTurnOrLess(float $start, float $end, string $ops): void
    {
        self::assertSame($ops, self::ops(Path::arc(0.0, 0.0, 10.0, $start, $end)));
    }

    #[Test]
    public function anArcRunsFromItsStartAngleToItsEndAngle(): void
    {
        $arc = Path::arc(5.0, 5.0, 10.0, 0.0, 90.0);

        self::assertEqualsWithDelta(
            [[15.0, 5.0], [5.0, 15.0]],
            [self::points($arc, 0), [self::points($arc, 1)[4] ?? 0.0, self::points($arc, 1)[5] ?? 0.0]],
            1.0e-9,
        );
    }

    #[Test]
    public function anArcsCubicsEndInsideIt(): void
    {
        self::assertSame([1 => true, 2 => true], Path::arc(0.0, 0.0, 1.0, 0.0, 180.0)->inner);
    }

    #[Test]
    public function anEllipseIsFourQuarterCurvesClosed(): void
    {
        self::assertSame('MCCCCZ', self::ops(Path::ellipse(0.0, 0.0, 2.0, 1.0)));
    }

    #[Test]
    public function anEmptyPathHasNoCommands(): void
    {
        self::assertSame([true, 0], [(new Path())->isEmpty(), count((new Path())->commands)]);
    }

    #[Test]
    public function anEmptyPathJoinsAsEmpty(): void
    {
        self::assertTrue((new Path())->joined()->isEmpty());
    }

    #[Test]
    public function aPathNotStartingWithAMoveJoinsAsItIs(): void
    {
        $path = new Path([['L', [1.0, 1.0]]]);

        self::assertSame($path, $path->joined());
    }

    #[Test]
    public function aPlainRectangleIsFourLinesClosed(): void
    {
        self::assertSame('MLLLZ', self::ops(Path::rect(0.0, 0.0, 10.0, 5.0)));
    }

    #[Test]
    public function aPolygonIsClosedAndAPolylineIsNot(): void
    {
        $points = [0.0, 0.0, 1.0, 0.0, 1.0, 1.0];

        self::assertSame(['MLL', 'MLLZ'], [self::ops(Path::polyline($points)), self::ops(Path::polygon($points))]);
    }

    #[Test]
    public function aPolygonWithoutPointsIsEmpty(): void
    {
        self::assertTrue(Path::polygon([])->isEmpty());
    }

    #[Test]
    public function appendingAnEmptyPathChangesNothing(): void
    {
        $line = Path::polyline([0.0, 0.0, 1.0, 1.0]);

        self::assertSame($line, $line->append(new Path()));
    }

    #[Test]
    public function appendingKeepsBothPathsAndTheirInnerCommands(): void
    {
        $line     = Path::polyline([0.0, 0.0, 1.0, 1.0]);
        $combined = $line->append(Path::arc(0.0, 0.0, 1.0, 0.0, 90.0));

        self::assertSame(['MLMC', [3 => true]], [self::ops($combined), $combined->inner]);
    }

    #[Test]
    public function aQuarterArcHasTheCircleControlPoints(): void
    {
        $curve = self::points(Path::arc(0.0, 0.0, 1.0, 0.0, 90.0), 1);

        self::assertEqualsWithDelta([1.0, Path::KAPPA, Path::KAPPA, 1.0, 0.0, 1.0], $curve, 1.0e-9);
    }

    #[Test]
    public function aRoundedRectanglesCornersAreHalfItsSidesAtMost(): void
    {
        $path = Path::rect(0.0, 0.0, 10.0, 4.0, 8.0, 8.0);

        self::assertSame(
            ['MLCLCLCLCZ', [5.0, 0.0], [5.0, 0.0]],
            [self::ops($path), self::points($path, 0), self::points($path, 1)],
        );
    }

    #[Test]
    #[DataProvider('boundsProvider')]
    public function theBoundsAreExact(Path $path, ?Box $expected): void
    {
        self::assertSame(self::rounded($expected), self::rounded($path->bounds()));
    }

    #[Test]
    public function theIdentityLeavesThePathAsItIs(): void
    {
        $path = Path::rect(0.0, 0.0, 1.0, 1.0);

        self::assertSame($path, $path->transformed(Matrix::identity()));
    }

    #[Test]
    public function transformingMovesEveryPointAndKeepsInnerCommands(): void
    {
        $arc   = Path::arc(0.0, 0.0, 1.0, 0.0, 90.0);
        $moved = $arc->transformed(Matrix::translation(10.0, 20.0));

        self::assertEqualsWithDelta(
            [[11.0, 20.0], $arc->inner],
            [self::points($moved, 0), $moved->inner],
            1.0e-9,
        );
    }
}
