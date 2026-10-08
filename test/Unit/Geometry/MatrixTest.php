<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Geometry;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Matrix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function abs;

#[Group('unit')]
final class MatrixTest extends TestCase
{
    /** @return array<string, array{0: callable(Matrix): Matrix, 1: array{0: float, 1: float}}> */
    public static function prependedProvider(): array
    {
        return [
            'rotate'    => [static fn(Matrix $m): Matrix => $m->rotate(90.0), [9.0, 1.0]],
            'scale'     => [static fn(Matrix $m): Matrix => $m->scale(2.0), [12.0, 2.0]],
            'translate' => [static fn(Matrix $m): Matrix => $m->translate(1.0, 3.0), [12.0, 4.0]],
        ];
    }

    /**
     * @param callable(Matrix): Matrix $prepend
     * @param array{0: float, 1: float} $expected
     */
    #[Test]
    #[DataProvider('prependedProvider')]
    public function appliesAPrependedTransformBeforeItself(callable $prepend, array $expected): void
    {
        self::assertSame($expected, $prepend(Matrix::translation(10.0, 0.0))->apply(1.0, 1.0));
    }

    #[Test]
    public function composesInPdfOrder(): void
    {
        // Scale first, then translate: the translation is not scaled.
        $m = Matrix::scaling(2.0)->then(Matrix::translation(10.0, 5.0));

        self::assertSame([12.0, 7.0], $m->apply(1.0, 1.0));
        self::assertSame('2 0 0 2 10 5', $m->toPdf());

        // Translate first, then scale: the translation is scaled.
        $m = Matrix::translation(10.0, 5.0)->then(Matrix::scaling(2.0));
        self::assertSame([22.0, 12.0], $m->apply(1.0, 1.0));
    }

    #[Test]
    public function invertsRoundTrip(): void
    {
        $m   = Matrix::translation(3.0, 4.0)->then(Matrix::rotation(30.0))->then(Matrix::scaling(2.0, 0.5));
        $inv = $m->inverse();
        [$x, $y] = $inv->apply(...$m->apply(7.0, -2.0));

        self::assertEqualsWithDelta(7.0, $x, 1e-9);
        self::assertEqualsWithDelta(-2.0, $y, 1e-9);
        self::assertTrue($m->then($inv)->isIdentity() || abs($m->then($inv)->a - 1.0) < 1e-9);
    }

    #[Test]
    public function refusesToInvertACollapsedMatrix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Matrix is not invertible.');
        Matrix::scaling(0.0, 1.0)->inverse();
    }

    #[Test]
    public function rotatesCounterClockwise(): void
    {
        [$x, $y] = Matrix::rotation(90.0)->apply(1.0, 0.0);

        self::assertEqualsWithDelta(0.0, $x, 1e-9);
        self::assertEqualsWithDelta(1.0, $y, 1e-9);
        self::assertSame('0 1 -1 0 0 0', Matrix::rotation(90.0)->toPdf());
    }

    #[Test]
    public function transformsBoxBounds(): void
    {
        $bounds = Matrix::rotation(90.0)->applyToBox(new Box(0, 0, 10, 20));

        self::assertEqualsWithDelta(-20.0, $bounds->x, 1e-9);
        self::assertEqualsWithDelta(0.0, $bounds->y, 1e-9);
        self::assertEqualsWithDelta(20.0, $bounds->width, 1e-9);
        self::assertEqualsWithDelta(10.0, $bounds->height, 1e-9);
    }
}
