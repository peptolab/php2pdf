<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Php2Pdf\Geometry\Path;
use Php2Pdf\Geometry\PathBuilder;

use function array_slice;
use function intdiv;
use function ord;
use function strlen;
use function substr;
use function unpack;

/**
 * The paths in Photoshop image resource blocks (from a JPEG's APP13 segments or a TIFF's tag
 * 34377): every named path, and the name of the one Photoshop marks as the clipping path.
 *
 * @internal
 */
final class PhotoshopResources
{
    private const int FIRST_PATH    = 2000;
    private const int LAST_PATH     = 2997;
    private const int CLIPPING_NAME = 2999;
    private const int RECORD        = 26;

    /** @var array<string, Path> */
    private array $paths = [];

    private ?string $clippingName = null;

    public function __construct(string $blocks)
    {
        $at     = 0;
        $length = strlen($blocks);
        while (($at + 12) <= $length && '8BIM' === substr($blocks, $at, length: 4)) {
            $id      = self::u16($blocks, $at + 4);
            $nameLen = ord($blocks[$at + 6] ?? "\0");
            $name    = substr($blocks, $at + 7, $nameLen);
            $at      += 6 + 1 + $nameLen + ((1 + $nameLen) % 2);
            $size    = self::u32($blocks, $at);
            $data    = substr($blocks, $at + 4, $size);
            $at      += 4 + $size + ($size % 2);
            if ($id >= self::FIRST_PATH && $id <= self::LAST_PATH) {
                $this->paths[$name] = self::parsePath($data);
            }
            if (self::CLIPPING_NAME === $id) {
                $this->clippingName = substr($data, offset: 1, length: ord($data[0] ?? "\0"));
            }
        }
    }

    /**
     * A closed subpath through its knots.
     *
     * @param non-empty-list<array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}, 2: array{0: float, 1: float}}> $knots
     */
    private static function addSubpath(PathBuilder $path, array $knots): void
    {
        $first    = $knots[0];
        $previous = $first;
        $path->moveTo($first[1][0], $first[1][1]);
        foreach ([...array_slice($knots, offset: 1), $first] as $knot) {
            $path->curveTo($previous[2][0], $previous[2][1], $knot[0][0], $knot[0][1], $knot[1][0], $knot[1][1]);
            $previous = $knot;
        }
        $path->close();
    }

    /** A signed 8.24 fixed-point number. */
    private static function fixed(string $data, int $at): float
    {
        $value = self::u32($data, $at);
        if ($value >= 0x8000_0000) {
            $value -= 0x1_0000_0000;
        }

        return $value / 16_777_216;
    }

    /**
     * The path in a path resource's 26-byte records, in the image's unit square (origin bottom-left,
     * y up). Each record is a Bézier knot: a control point before the anchor, the anchor, and a
     * control point after it. Open subpaths are closed for clipping.
     */
    private static function parsePath(string $data): Path
    {
        $subpaths = [];
        $knots    = [];
        $records  = intdiv(strlen($data), self::RECORD);
        for ($i = 0; $i < $records; ++$i) {
            $at       = $i * self::RECORD;
            $selector = self::u16($data, $at);
            if (0 === $selector || 3 === $selector) {
                if ([] !== $knots) {
                    $subpaths[] = $knots;
                }
                $knots = [];
                continue;
            }
            if ($selector < 1 || $selector > 5) {
                continue;
            }
            $point =
                /** @return array{0: float, 1: float} */
                static fn(int $offset): array => [
                    self::fixed($data, $at + $offset + 4),
                    1.0 - self::fixed($data, $at + $offset),
                ];
            $knots[] = [$point(2), $point(10), $point(18)];
        }
        if ([] !== $knots) {
            $subpaths[] = $knots;
        }

        $path = new PathBuilder();
        foreach ($subpaths as $knots) {
            self::addSubpath($path, $knots);
        }

        return $path->build();
    }

    private static function u16(string $data, int $at): int
    {
        return (int) unpack('n', substr($data, $at, length: 2) . "\0\0")[1];
    }

    private static function u32(string $data, int $at): int
    {
        return (int) unpack('N', substr($data, $at, length: 4) . "\0\0\0\0")[1];
    }

    /** The path Photoshop marks as the clipping path, or null. */
    public function clippingPath(): ?Path
    {
        return null === $this->clippingName ? null : $this->paths[$this->clippingName] ?? null;
    }

    public function path(string $name): ?Path
    {
        return $this->paths[$name] ?? null;
    }
}
