<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Override;
use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\ParseException;

use function implode;
use function ksort;
use function ord;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Reads the frame header and metadata of a JPEG. The compressed data is passed through to PDF
 * untouched (DCTDecode); nothing is re-encoded.
 *
 * @internal
 */
final class JpegDecoder implements ImageDecoderInterface
{
    /** @var array{precision: int, height: int, width: int, components: int}|null */
    private ?array $frame = null;

    private bool $adobe = false;

    /** @var array<int, string> ICC profile chunks by sequence number */
    private array $icc = [];

    /** @var array{0: float, 1: float}|null density from the JFIF header, when it gives one */
    private ?array $dpi = null;

    /** @var array{0: float, 1: float}|null density from the EXIF resolution tags */
    private ?array $exifDpi = null;

    private int $orientation = 1;

    /** Photoshop image resource blocks from APP13 segments. */
    private string $photoshop = '';

    /**
     * @return array{precision: int, height: int, width: int, components: int}
     *
     * @throws ParseException
     */
    private static function frame(string $segment): array
    {
        if (strlen($segment) < 6) {
            throw new ParseException('Truncated JPEG frame header.');
        }

        return [
            'precision'  => ord($segment[0]),
            'height'     => (ord($segment[1]) << 8) | ord($segment[2]),
            'width'      => (ord($segment[3]) << 8) | ord($segment[4]),
            'components' => ord($segment[5]),
        ];
    }

    private static function isFrameMarker(int $marker): bool
    {
        return 0xC0 <= $marker && $marker <= 0xCF && 0xC4 !== $marker && 0xC8 !== $marker && 0xCC !== $marker;
    }

    /**
     * The JFIF density in dots per inch, or null when the header gives only an aspect ratio.
     *
     * @return array{0: float, 1: float}|null
     */
    private static function jfifDensity(string $segment): ?array
    {
        if (strlen($segment) < 12) {
            return null;
        }
        $units = ord($segment[7]);
        $x     = (ord($segment[8]) << 8) | ord($segment[9]);
        $y     = (ord($segment[10]) << 8) | ord($segment[11]);
        if (0 === $x || 0 === $y) {
            return null;
        }

        return match ($units) {
            1       => [(float) $x, (float) $y],
            2       => [$x * 2.54, $y * 2.54],
            default => null,
        };
    }

    #[Override]
    public function accepts(string $data): bool
    {
        return str_starts_with($data, "\xFF\xD8");
    }

    /** @throws ParseException for a malformed file or an image over the limits */
    #[Override]
    public function decode(string $data, Limits $limits, ImageOptions $options): ImageData
    {
        if (! $this->accepts($data)) {
            throw new ParseException('Not a JPEG file.');
        }
        $decoder = new self();
        $decoder->scan($data);
        $frame = $decoder->frame ?? throw new ParseException('JPEG has no frame header.');
        $limits->assertImagePixels($frame['width'], $frame['height']);
        if (8 !== $frame['precision']) {
            throw new ParseException(sprintf('Only 8-bit JPEGs are supported, %d-bit given.', $frame['precision']));
        }
        $colorSpace = match ($frame['components']) {
            1       => ImageColorSpace::gray(),
            3       => ImageColorSpace::rgb(),
            4       => ImageColorSpace::cmyk(),
            default => throw new ParseException(sprintf('Unsupported JPEG component count %d.', $frame['components'])),
        };
        ksort($decoder->icc);

        return new ImageData(
            new Raster(
                $frame['width'],
                $frame['height'],
                8,
                $colorSpace,
                $data,
                'DCTDecode',
                decode: 4 === $frame['components'] && $decoder->adobe ? [1, 0, 1, 0, 1, 0, 1, 0] : null,
                iccProfile: [] === $decoder->icc ? null : implode('', $decoder->icc),
            ),
            dpiX: ($decoder->dpi ?? $decoder->exifDpi ?? [72.0, 72.0])[0],
            dpiY: ($decoder->dpi ?? $decoder->exifDpi ?? [72.0, 72.0])[1],
            orientation: $decoder->orientation,
            photoshop: '' === $decoder->photoshop ? null : $decoder->photoshop,
        );
    }

    #[Override]
    public function hasPages(): bool
    {
        return false;
    }

    #[Override]
    public function name(): string
    {
        return 'JPEG';
    }

    /**
     * Walks the marker segments up to the start of scan data.
     *
     * @throws ParseException
     */
    private function scan(string $data): void
    {
        $length = strlen($data);
        $offset = 2;
        while (($offset + 4) <= $length) {
            if ("\xFF" !== $data[$offset]) {
                ++$offset;
                continue;
            }
            $marker = ord($data[$offset + 1]);
            if (0xFF === $marker) {
                ++$offset;
                continue;
            }
            if (0xD8 === $marker || (0xD0 <= $marker && $marker <= 0xD7) || 0x01 === $marker) {
                $offset += 2;
                continue;
            }
            if (0xDA === $marker || 0xD9 === $marker) {
                return;
            }
            $segmentLength = (ord($data[$offset + 2]) << 8) | ord($data[$offset + 3]);
            $this->segment($marker, substr($data, $offset + 4, $segmentLength - 2));
            $offset += 2 + $segmentLength;
        }
    }

    /** @throws ParseException */
    private function segment(int $marker, string $segment): void
    {
        if (self::isFrameMarker($marker)) {
            $this->frame = self::frame($segment);

            return;
        }
        if (0xE0 === $marker && str_starts_with($segment, "JFIF\0")) {
            $this->dpi = self::jfifDensity($segment);

            return;
        }
        if (0xE1 === $marker && str_starts_with($segment, "Exif\0\0")) {
            $exif              = Exif::read(substr($segment, offset: 6));
            $this->orientation = $exif->orientation;
            $this->exifDpi     = $exif->dpi;

            return;
        }
        if (0xED === $marker && str_starts_with($segment, "Photoshop 3.0\0")) {
            $this->photoshop .= substr($segment, offset: 14);

            return;
        }
        if (0xEE === $marker && str_starts_with($segment, 'Adobe')) {
            $this->adobe = true;

            return;
        }
        if (0xE2 === $marker && str_starts_with($segment, "ICC_PROFILE\0") && strlen($segment) > 14) {
            $this->icc[ord($segment[12])] = substr($segment, offset: 14);
        }
    }
}
