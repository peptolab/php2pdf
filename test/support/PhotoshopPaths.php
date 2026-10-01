<?php

declare(strict_types=1);

namespace Php2PdfTest\Support;

use function chr;
use function count;
use function pack;
use function round;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Photoshop image resource blocks holding paths, as Photoshop saves them in JPEG APP13 segments
 * and TIFF tag 34377. Points are [vertical, horizontal] fractions of the image from its top left.
 */
final class PhotoshopPaths
{
    /** A diamond touching the middle of each edge. */
    public const array DIAMOND = [[0.0, 0.5], [0.5, 1.0], [1.0, 0.5], [0.5, 0.0]];

    /**
     * Resource blocks with a closed straight-sided path for each name, and the name Photoshop
     * marks as the clipping path.
     *
     * @param array<string, list<array{0: float, 1: float}>> $paths
     */
    public static function blocks(array $paths, ?string $clipping = null): string
    {
        $blocks = '';
        $id     = 2000;
        foreach ($paths as $name => $points) {
            $records = pack('n', 6) . str_repeat("\0", times: 24);
            $records .= pack('n', 0) . pack('n', count($points)) . str_repeat("\0", times: 22);
            foreach ($points as $point) {
                $fixed   = pack('NN', self::fixed($point[0]), self::fixed($point[1]));
                $records .= pack('n', 2) . $fixed . $fixed . $fixed;
            }
            $blocks .= self::block($id++, $name, $records);
        }
        if (null !== $clipping) {
            $blocks .= self::block(2999, '', chr(strlen($clipping)) . $clipping . pack('n', 0));
        }

        return $blocks;
    }

    /** A JPEG with resource blocks in an APP13 segment after its start-of-image marker. */
    public static function jpeg(string $jpeg, string $blocks): string
    {
        $segment = "Photoshop 3.0\0{$blocks}";

        return (
            substr($jpeg, offset: 0, length: 2)
                . "\xFF\xED"
                . pack('n', strlen($segment) + 2)
                . $segment
                . substr($jpeg, offset: 2)
        );
    }

    private static function block(int $id, string $name, string $data): string
    {
        $pascal = chr(strlen($name)) . $name;
        if (0 !== (strlen($pascal) % 2)) {
            $pascal .= "\0";
        }
        $padded = 0 === (strlen($data) % 2) ? $data : "{$data}\0";

        return '8BIM' . pack('n', $id) . $pascal . pack('N', strlen($data)) . $padded;
    }

    private static function fixed(float $value): int
    {
        return (int) round($value * 16_777_216) & 0xFFFF_FFFF;
    }
}
