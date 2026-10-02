<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Fit;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Fit\FitEngine;
use Php2Pdf\Fit\FitMethod;
use Php2Pdf\Fit\Position;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Origin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FitEngineTest extends TestCase
{
    /** @return iterable<string, array{0: FitMethod, 1: list<float>, 2: float, 3: float, 4: bool}> */
    public static function methods(): iterable
    {
        // A 200x100 object into a 100x100 box, positioned at the centre.
        yield 'nofit keeps size' => [FitMethod::NoFit, [-50.0, 0.0, 150.0, 100.0], 1.0, 1.0, false];
        yield 'clip keeps size and clips' => [FitMethod::Clip, [-50.0, 0.0, 150.0, 100.0], 1.0, 1.0, true];
        yield 'meet fits inside' => [FitMethod::Meet, [0.0, 25.0, 100.0, 75.0], 0.5, 0.5, false];
        yield 'slice covers and clips' => [FitMethod::Slice, [-50.0, 0.0, 150.0, 100.0], 1.0, 1.0, true];
        yield 'entire stretches' => [FitMethod::Entire, [0.0, 0.0, 100.0, 100.0], 0.5, 1.0, false];
        yield 'auto shrinks when too big' => [FitMethod::Auto, [0.0, 25.0, 100.0, 75.0], 0.5, 0.5, false];
    }

    /** @return array<string, array{0: FitMethod}> */
    public static function scalingMethodProvider(): array
    {
        return [
            'meet'   => [FitMethod::Meet],
            'slice'  => [FitMethod::Slice],
            'entire' => [FitMethod::Entire],
            'auto'   => [FitMethod::Auto],
            'clip'   => [FitMethod::Clip],
        ];
    }

    #[Test]
    #[DataProvider('scalingMethodProvider')]
    public function anEmptyBoxCannotBeFittedInto(FitMethod $method): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fitting into a 0 × 50 box needs a positive width and height.');
        FitEngine::place(10.0, 10.0, new Box(0.0, 0.0, 0.0, 50.0), new Fit($method), Origin::BottomLeft);
    }

    #[Test]
    #[DataProvider('methods')]
    public function appliesFitMethods(FitMethod $method, array $bounds, float $sx, float $sy, bool $clips): void
    {
        $box       = new Box(0.0, 0.0, 100.0, 100.0);
        $placement = FitEngine::place(200.0, 100.0, $box, new Fit($method), Origin::BottomLeft);

        self::assertEqualsWithDelta($bounds, $placement->bounds->toRect(), 1e-9);
        self::assertEqualsWithDelta($sx, $placement->scaleX, 1e-9);
        self::assertEqualsWithDelta($sy, $placement->scaleY, 1e-9);
        self::assertSame($clips, null !== $placement->clip);
        // The matrix maps the object's corners onto the bounds.
        self::assertEqualsWithDelta([$bounds[0], $bounds[1]], $placement->matrix->apply(0.0, 0.0), 1e-9);
        self::assertEqualsWithDelta([$bounds[2], $bounds[3]], $placement->matrix->apply(200.0, 100.0), 1e-9);
    }

    #[Test]
    public function autoKeepsSmallObjectsAtNaturalSize(): void
    {
        $placement = FitEngine::place(
            20.0,
            10.0,
            new Box(0.0, 0.0, 100.0, 100.0),
            Fit::auto(Position::topRight()),
            Origin::BottomLeft,
        );

        self::assertEqualsWithDelta([80.0, 90.0, 100.0, 100.0], $placement->bounds->toRect(), 1e-9);
        self::assertFalse($placement->overflows(new Box(0.0, 0.0, 100.0, 100.0)));
    }

    /** Without fitting, a zero-size box is just a point; the default centre position centres on it. */
    #[Test]
    public function noFitPlacesAtTheBoxWhateverItsSize(): void
    {
        $placement = FitEngine::place(
            10.0,
            10.0,
            new Box(5.0, 5.0, 0.0, 0.0),
            new Fit(FitMethod::NoFit),
            Origin::BottomLeft,
        );

        self::assertSame([0.0, 0.0, 10.0, 10.0], [
            $placement->bounds->x,
            $placement->bounds->y,
            $placement->bounds->width,
            $placement->bounds->height,
        ]);
    }

    #[Test]
    public function positionsInsideTheBox(): void
    {
        $box = new Box(10.0, 10.0, 100.0, 100.0);

        $bl = FitEngine::place(50.0, 20.0, $box, Fit::noFit(Position::bottomLeft()), Origin::BottomLeft);
        self::assertEqualsWithDelta([10.0, 10.0, 60.0, 30.0], $bl->bounds->toRect(), 1e-9);

        $tr = FitEngine::place(50.0, 20.0, $box, Fit::noFit(Position::topRight()), Origin::BottomLeft);
        self::assertEqualsWithDelta([60.0, 90.0, 110.0, 110.0], $tr->bounds->toRect(), 1e-9);

        $pct = FitEngine::place(50.0, 20.0, $box, Fit::noFit(Position::percent(25.0, 75.0)), Origin::BottomLeft);
        self::assertEqualsWithDelta([22.5, 70.0, 72.5, 90.0], $pct->bounds->toRect(), 1e-9);
    }

    #[Test]
    public function rejectsEmptyObjects(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FitEngine::place(0.0, 10.0, new Box(0.0, 0.0, 1.0, 1.0), new Fit(), Origin::BottomLeft);
    }

    #[Test]
    public function rotatesAboutTheFittedBounds(): void
    {
        $box       = new Box(0.0, 0.0, 100.0, 100.0);
        $placement = FitEngine::place(200.0, 100.0, $box, Fit::meet(Position::bottomLeft(), 90.0), Origin::BottomLeft);

        // Rotated 90°, the object is 100 wide and 200 tall, so meet scales by 0.5: bounds 50 x 100.
        self::assertEqualsWithDelta([0.0, 0.0, 50.0, 100.0], $placement->bounds->toRect(), 1e-9);
        self::assertEqualsWithDelta([50.0, 0.0], $placement->matrix->apply(0.0, 0.0), 1e-9);
        self::assertEqualsWithDelta([0.0, 100.0], $placement->matrix->apply(200.0, 100.0), 1e-9);
    }

    #[Test]
    public function scalesNaturalSizeBeforeFitting(): void
    {
        $placement = FitEngine::place(
            10.0,
            10.0,
            new Box(0.0, 0.0, 100.0, 100.0),
            Fit::noFit(Position::bottomLeft(), 0.0, 3.0),
            Origin::BottomLeft,
        );

        self::assertEqualsWithDelta([0.0, 0.0, 30.0, 30.0], $placement->bounds->toRect(), 1e-9);
        self::assertEqualsWithDelta([30.0, 30.0], $placement->matrix->apply(10.0, 10.0), 1e-9);
        self::assertEqualsWithDelta(3.0, $placement->scaleX, 1e-9);
    }

    #[Test]
    public function topDownFlipsVerticalPositionAndKeepsObjectsUpright(): void
    {
        $box = new Box(10.0, 10.0, 100.0, 100.0);
        $top = FitEngine::place(50.0, 20.0, $box, Fit::noFit(Position::topLeft()), Origin::TopLeft);

        // "Top" is the smallest y on a top-down page.
        self::assertEqualsWithDelta([10.0, 10.0, 60.0, 30.0], $top->bounds->toRect(), 1e-9);
        // The object's bottom-left corner (0,0) lands at the visual bottom, i.e. the larger y.
        self::assertEqualsWithDelta([10.0, 30.0], $top->matrix->apply(0.0, 0.0), 1e-9);
        self::assertEqualsWithDelta([60.0, 10.0], $top->matrix->apply(50.0, 20.0), 1e-9);
    }
}
