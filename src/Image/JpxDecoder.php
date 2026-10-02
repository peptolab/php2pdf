<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Override;
use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\ParseException;

use function ord;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * Reads the header of a JPEG 2000 image (a JP2 file or a bare codestream). The data is passed
 * through to PDF untouched (JPXDecode); readers decode it, so only the size, components, depth
 * and colour space are read here.
 *
 * @internal
 */
final readonly class JpxDecoder implements ImageDecoderInterface
{
    public const string JP2_SIGNATURE = "\x00\x00\x00\x0CjP  \r\n\x87\n";

    public const string CODESTREAM = "\xFF\x4F\xFF\x51";

    private const int SRGB      = 16;
    private const int GREYSCALE = 17;
    private const int SYCC      = 18;

    /**
     * Finds a box of a type between two offsets, returning where its contents start and end.
     *
     * @return array{0: int, 1: int}|null
     *
     * @throws ParseException
     */
    private static function box(BinaryReader $r, int $from, int $to, string $type): ?array
    {
        $at = $from;
        while (($at + 8) <= $to) {
            $length = $r->u32($at);
            $kind   = substr($r->data, $at + 4, length: 4);
            $start  = $at + 8;
            if (1 === $length) {
                $length = ($r->u32($at + 8) << 32) | $r->u32($at + 12);
                $start  = $at + 16;
            }
            $end = 0 === $length ? $to : $at + $length;
            if ($end > $to || $end <= $at) {
                return null;
            }
            if ($kind === $type) {
                return [$start, $end];
            }
            $at = $end;
        }

        return null;
    }

    /**
     * The size and depth from a bare codestream's SIZ marker.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: null, 5: null, 6: bool}
     *
     * @throws ParseException
     */
    private static function codestream(BinaryReader $r): array
    {
        if ($r->length < 43) {
            throw new ParseException('Truncated JPEG 2000 codestream.');
        }
        return [
            $r->u32(8) - $r->u32(16),
            $r->u32(12) - $r->u32(20),
            $r->u16(40),
            (ord($r->data[42]) & 0x7F) + 1,
            null,
            null,
            false,
        ];
    }

    /**
     * Whether a channel definition box marks a channel as opacity.
     *
     * @param array{0: int, 1: int}|null $cdef
     *
     * @throws ParseException
     */
    private static function hasAlpha(BinaryReader $r, ?array $cdef): bool
    {
        if (null === $cdef || ($cdef[1] - $cdef[0]) < 2) {
            return false;
        }
        $count = $r->u16($cdef[0]);
        for ($i = 0; $i < $count; ++$i) {
            $at = $cdef[0] + 2 + ($i * 6);
            if (($at + 6) > $cdef[1]) {
                return false;
            }
            $type = $r->u16($at + 2);
            if (1 === $type || 2 === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * The size, depth and colour space from a JP2 file's header boxes, and whether a channel
     * definition gives it an alpha channel.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int|null, 5: string|null, 6: bool}
     *
     * @throws ParseException
     */
    private static function jp2(BinaryReader $r): array
    {
        if (! str_starts_with($r->data, self::JP2_SIGNATURE)) {
            throw new ParseException('Not a JPEG 2000 file.');
        }
        $header = self::box($r, 12, $r->length, 'jp2h') ?? throw new ParseException(
            'JPEG 2000 file has no header box.',
        );
        $ihdr = self::box($r, $header[0], $header[1], 'ihdr') ?? throw new ParseException(
            'JPEG 2000 file has no image header.',
        );
        if (($ihdr[1] - $ihdr[0]) < 11) {
            throw new ParseException('Truncated JPEG 2000 image header.');
        }
        $enumerated = null;
        $icc        = null;
        $colr       = self::box($r, $header[0], $header[1], 'colr');
        if (null !== $colr && 1 === ord($r->data[$colr[0]] ?? "\0") && ($colr[1] - $colr[0]) >= 7) {
            $enumerated = $r->u32($colr[0] + 3);
        }
        if (null !== $colr && 2 === ord($r->data[$colr[0]] ?? "\0")) {
            $icc = substr($r->data, $colr[0] + 3, $colr[1] - $colr[0] - 3);
        }

        return [
            $r->u32($ihdr[0] + 4),
            $r->u32($ihdr[0]),
            $r->u16($ihdr[0] + 8),
            (ord($r->data[$ihdr[0] + 10] ?? "\0") & 0x7F) + 1,
            $enumerated,
            $icc,
            self::hasAlpha($r, self::box($r, $header[0], $header[1], 'cdef')),
        ];
    }

    #[Override]
    public function accepts(string $data): bool
    {
        return str_starts_with($data, self::JP2_SIGNATURE) || str_starts_with($data, self::CODESTREAM);
    }

    /** @throws ParseException for a malformed file or an image over the limits */
    #[Override]
    public function decode(string $data, Limits $limits, ImageOptions $options): ImageData
    {
        $reader = new BinaryReader($data, what: 'the JPEG 2000 data');
        [$width, $height, $components, $bits, $enumerated, $icc, $alpha] = str_starts_with($data, self::CODESTREAM)
            ? self::codestream($reader)
            : self::jp2($reader);
        if ($width < 1 || $height < 1) {
            throw new ParseException('JPEG 2000 image has no pixels.');
        }
        $limits->assertImagePixels($width, $height);
        $colours = $components - ($alpha ? 1 : 0);
        $space   = match (true) {
            self::GREYSCALE === $enumerated, 1 === $colours => ImageColorSpace::gray(),
            self::SRGB === $enumerated, self::SYCC === $enumerated, 3 === $colours => ImageColorSpace::rgb(),
            4 === $colours => ImageColorSpace::cmyk(),
            default => throw new ParseException(sprintf(
                'JPEG 2000 images with %d components are not supported.',
                $components,
            )),
        };

        return new ImageData(new Raster(
            $width,
            $height,
            $bits,
            $space,
            $data,
            'JPXDecode',
            iccProfile: $icc,
            smaskInData: $alpha,
        ));
    }

    #[Override]
    public function hasPages(): bool
    {
        return false;
    }

    #[Override]
    public function name(): string
    {
        return 'JPEG 2000';
    }
}
