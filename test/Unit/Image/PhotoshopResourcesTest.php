<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Php2Pdf\Geometry\Box;
use Php2Pdf\Image\PhotoshopResources;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pack;
use function str_repeat;
use function strlen;

/** Paths are read from Photoshop's resource blocks as subpaths of 8.24 fixed-point knots. */
#[Group('unit')]
final class PhotoshopResourcesTest extends TestCase
{
    private static function fixed(float $value): int
    {
        return (int) ($value * 16_777_216) & 0xFFFF_FFFF;
    }

    private static function resources(string $records): PhotoshopResources
    {
        return new PhotoshopResources('8BIM' . pack('n', 2000) . "\x01A" . pack('N', strlen($records)) . $records);
    }

    /**
     * A subpath length record followed by a straight-sided knot at each [vertical, horizontal] point.
     *
     * @param list<array{0: float, 1: float}> $points
     */
    private static function subpath(array $points): string
    {
        $records = pack('n', 0) . str_repeat("\0", times: 24);
        foreach ($points as [$vertical, $horizontal]) {
            $point   = pack('NN', self::fixed($vertical), self::fixed($horizontal));
            $records .= pack('n', 2) . $point . $point . $point;
        }

        return $records;
    }

    #[Test]
    public function readsEverySubpathOfAPath(): void
    {
        $path = self::resources(
            self::subpath([[0.0, 0.0], [0.0, 0.25], [0.25, 0.0]])
                . self::subpath([[0.75, 1.0], [1.0, 1.0], [1.0, 0.75]]),
        )
            ->path('A');

        self::assertEquals(new Box(0.0, 0.0, 1.0, 1.0), $path?->bounds());
    }

    #[Test]
    public function readsPointsOutsideTheImage(): void
    {
        $path = self::resources(self::subpath([[0.0, -0.5], [0.0, 0.5], [0.5, 0.0]]))->path('A');

        self::assertEquals(new Box(-0.5, 0.5, 1.0, 0.5), $path?->bounds());
    }
}
