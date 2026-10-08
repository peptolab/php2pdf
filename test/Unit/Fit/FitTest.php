<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Fit;

use Closure;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Fit\FitMethod;
use Php2Pdf\Fit\Position;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FitTest extends TestCase
{
    /** @return array<string, array{0: Closure(): Fit, 1: string}> */
    public static function invalidFitProvider(): array
    {
        return [
            'zero horizontal scale'   => [
                static fn(): Fit => new Fit(scaleX: 0.0),
                'Scale factors must be positive.',
            ],
            'negative vertical scale' => [
                static fn(): Fit => new Fit(scaleY: -1.0),
                'Scale factors must be positive.',
            ],
            'zero resolution'         => [
                static fn(): Fit => new Fit(dpi: 0.0),
                'Resolution must be positive.',
            ],
        ];
    }

    #[Test]
    public function clipKeepsTheGivenScale(): void
    {
        $fit = Fit::clip(Position::topLeft(), rotate: 0.0, scale: 2.0);

        self::assertEquals(
            new Fit(FitMethod::Clip, Position::topLeft(), rotate: 0.0, scaleX: 2.0),
            $fit,
        );
    }

    /** @param Closure(): Fit $make */
    #[Test]
    #[DataProvider('invalidFitProvider')]
    public function rejectsInvalidScalesAndResolutions(Closure $make, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $make();
    }

    #[Test]
    public function withPositionKeepsEverythingElse(): void
    {
        $fit = (new Fit(FitMethod::Slice, null, rotate: 90.0, scaleX: 2.0, scaleY: 3.0, dpi: 150.0))->withPosition(
            Position::bottomRight(),
        );

        self::assertEquals(
            new Fit(FitMethod::Slice, Position::bottomRight(), rotate: 90.0, scaleX: 2.0, scaleY: 3.0, dpi: 150.0),
            $fit,
        );
    }

    #[Test]
    public function withRotationKeepsEverythingElse(): void
    {
        $fit = (new Fit(
            FitMethod::Slice,
            Position::topLeft(),
            rotate: 0.0,
            scaleX: 2.0,
            scaleY: 3.0,
            dpi: 150.0,
        ))->withRotation(45.0);

        self::assertEquals(
            new Fit(FitMethod::Slice, Position::topLeft(), rotate: 45.0, scaleX: 2.0, scaleY: 3.0, dpi: 150.0),
            $fit,
        );
    }
}
