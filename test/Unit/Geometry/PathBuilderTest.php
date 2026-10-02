<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Geometry;

use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Geometry\Path;
use Php2Pdf\Geometry\PathBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use const M_PI;

#[Group('unit')]
final class PathBuilderTest extends TestCase
{
    /** @return list<float> the coordinates of a path's command */
    private static function points(Path $path, int $index): array
    {
        return ($path->commands[$index] ?? ['Z', []])[1];
    }

    #[Test]
    public function aQuadraticCurveIsRaisedToACubic(): void
    {
        $path = (new PathBuilder())->moveTo(0.0, 0.0)
            ->quadTo(3.0, 3.0, 6.0, 0.0)
            ->build();

        self::assertEqualsWithDelta([2.0, 2.0, 4.0, 2.0, 6.0, 0.0], self::points($path, 1), 1.0e-9);
    }

    #[Test]
    public function arcSegmentsFollowTheEllipseTheyAreMappedBy(): void
    {
        $ellipse = new Matrix(2.0, 0.0, 0.0, 1.0, 10.0, 10.0);
        $path    = (new PathBuilder())->moveTo(12.0, 10.0)
            ->arcSegments($ellipse, 0.0, M_PI)
            ->build();

        self::assertEqualsWithDelta(
            [[10.0, 11.0], [8.0, 10.0]],
            [
                [self::points($path, 1)[4] ?? 0.0, self::points($path, 1)[5] ?? 0.0],
                [self::points($path, 2)[4] ?? 0.0, self::points($path, 2)[5] ?? 0.0],
            ],
            1.0e-9,
        );
    }

    #[Test]
    public function closingReturnsToTheSubpathsStart(): void
    {
        $builder = (new PathBuilder())->moveTo(1.0, 2.0)
            ->lineTo(5.0, 6.0)
            ->close();

        self::assertSame([1.0, 2.0], $builder->point());
    }

    #[Test]
    public function continuingMarksTheLastCommandAsInner(): void
    {
        $path = (new PathBuilder())->moveTo(0.0, 0.0)
            ->lineTo(1.0, 0.0)
            ->continues()
            ->lineTo(2.0, 0.0)
            ->build();

        self::assertSame([1 => true], $path->inner);
    }

    #[Test]
    public function theCurrentPointFollowsEachCommand(): void
    {
        $builder = (new PathBuilder())->moveTo(0.0, 0.0)
            ->curveTo(1.0, 1.0, 2.0, 2.0, 3.0, 4.0);

        self::assertSame([3.0, 4.0], $builder->point());
    }
}
