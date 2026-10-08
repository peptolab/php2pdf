<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Svg\Viewport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A 10 by 10 viewBox meeting a 20 by 10 viewport: scale 1, with 10 units of horizontal slack. */
#[Group('unit')]
final class ViewportTest extends TestCase
{
    /** @return array<string, array{0: string, 1: float}> */
    public static function alignmentProvider(): array
    {
        return [
            'xMin'             => ['xMinYMin meet', 0.0],
            'xMid'             => ['xMidYMid meet', 5.0],
            'xMax'             => ['xMaxYMax meet', 10.0],
            'defer is ignored' => ['defer xMinYMid', 0.0],
        ];
    }

    #[Test]
    #[DataProvider('alignmentProvider')]
    public function theAlignmentPlacesTheViewBoxInTheSlack(string $preserve, float $offset): void
    {
        self::assertSame($offset, Viewport::mapping([0.0, 0.0, 10.0, 10.0], 20.0, 10.0, $preserve)->e);
    }
}
