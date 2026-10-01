<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Php2Pdf\Exception\ParseException;

use function is_float;
use function is_int;

/**
 * The orientation and resolution tags of an EXIF block (a TIFF header and its first image
 * directory), as JPEG files from cameras and phones carry them.
 *
 * @internal
 */
final readonly class Exif
{
    private const int TAG_ORIENTATION     = 0x0112;
    private const int TAG_X_RESOLUTION    = 0x011A;
    private const int TAG_Y_RESOLUTION    = 0x011B;
    private const int TAG_RESOLUTION_UNIT = 0x0128;

    /**
     * @param int                            $orientation 1 to 8, as EXIF numbers them
     * @param array{0: float, 1: float}|null $dpi         the resolution in dots per inch, when given
     */
    private function __construct(
        public int $orientation,
        public ?array $dpi,
    ) {}

    /** Reads the tags from the TIFF data that follows "Exif\0\0" in an APP1 segment. */
    public static function read(string $tiff): self
    {
        try {
            return self::tags(TiffFile::isTiff($tiff) ? new TiffFile($tiff) : null);
        } catch (ParseException) {
            return new self(1, null);
        }
    }

    /** The tags of the first directory; malformed ones read as absent. */
    private static function tags(?TiffFile $tags): self
    {
        $orientation = $tags?->value(0, self::TAG_ORIENTATION);
        $x           = $tags?->value(0, self::TAG_X_RESOLUTION);
        $y           = $tags?->value(0, self::TAG_Y_RESOLUTION);
        $unit        = $tags?->value(0, self::TAG_RESOLUTION_UNIT) ?? 2;
        $dpi         = null;
        if (is_float($x) && is_float($y) && $x > 0.0 && $y > 0.0 && (2 === $unit || 3 === $unit)) {
            $dpi = 3 === $unit ? [$x * 2.54, $y * 2.54] : [$x, $y];
        }

        return new self(is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1, $dpi);
    }
}
