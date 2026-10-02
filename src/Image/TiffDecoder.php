<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Php2Pdf\Core\Filter\Flate;
use Php2Pdf\Core\Filter\Lzw;
use Php2Pdf\Core\Filter\Predictor;
use Php2Pdf\Core\Limits;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Exception\ParseException;

use function array_unique;
use function chr;
use function count;
use function in_array;
use function intdiv;
use function is_float;
use function is_int;
use function ord;
use function preg_replace;
use function sprintf;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Reads baseline TIFF images natively, keeping CMYK, palettes and embedded ICC profiles: strips
 * uncompressed or compressed with LZW, Deflate or PackBits (with the horizontal predictor), in
 * grey, RGB, palette or CMYK at 1 to 16 bits, with an unassociated alpha channel. A single strip
 * of CCITT Group 3 or 4 fax data passes through untouched (CCITTFaxDecode). Other layouts (tiles,
 * JPEG compression, YCbCr, premultiplied alpha) return null, for the transcoder to handle.
 *
 * @internal
 */
final class TiffDecoder
{
    private const int WIDTH         = 256;
    private const int HEIGHT        = 257;
    private const int BITS          = 258;
    private const int COMPRESSION   = 259;
    private const int PHOTOMETRIC   = 262;
    private const int FILL_ORDER    = 266;
    private const int STRIP_OFFSETS = 273;
    private const int ORIENTATION   = 274;
    private const int SAMPLES       = 277;
    private const int STRIP_COUNTS  = 279;
    private const int X_RESOLUTION  = 282;
    private const int Y_RESOLUTION  = 283;
    private const int PLANAR        = 284;
    private const int T4_OPTIONS    = 292;
    private const int UNIT          = 296;
    private const int PREDICTOR     = 317;
    private const int COLOR_MAP     = 320;
    private const int TILE_WIDTH    = 322;
    private const int INK_SET       = 332;
    private const int EXTRA_SAMPLES = 338;
    private const int PHOTOSHOP     = 34_377;
    private const int ICC_PROFILE   = 34_675;

    private const int NONE          = 1;
    private const int CCITT_GROUP_3 = 3;
    private const int CCITT_GROUP_4 = 4;
    private const int LZW           = 5;
    private const int DEFLATE       = 8;
    private const int PACKBITS      = 32_773;
    private const int ADOBE_DEFLATE = 32_946;

    private const int WHITE_IS_ZERO = 0;
    private const int BLACK_IS_ZERO = 1;
    private const int RGB           = 2;
    private const int PALETTE       = 3;
    private const int SEPARATED     = 5;

    private function __construct(
        private readonly TiffFile $file,
        private readonly int $ifd,
        private readonly Limits $limits,
    ) {}

    /**
     * The image on a page of a TIFF file, or null when its layout is not read natively.
     *
     * @throws ParseException for a malformed file, a missing page or an image over the limits
     */
    public static function decode(string $data, Limits $limits, int $page = 1): ?ImageData
    {
        $file = new TiffFile($data);
        if ($page > $file->directoryCount()) {
            throw new ParseException(sprintf(
                'The image has %d pages; page %d was asked for.',
                $file->directoryCount(),
                $page,
            ));
        }

        return (new self($file, $page - 1, $limits))->read();
    }

    /** Decodes PackBits run-length data. */
    private static function packBits(string $data): string
    {
        $out    = '';
        $length = strlen($data);
        $i      = 0;
        while ($i < $length) {
            $n = ord($data[$i++]);
            if ($n < 128) {
                $out .= substr($data, $i, $n + 1);
                $i   += $n + 1;
                continue;
            }
            if (128 === $n) {
                continue;
            }
            $out .= str_repeat($data[$i] ?? "\0", 257 - $n);
            ++$i;
        }

        return $out;
    }

    private function colorSpace(int $photometric, int $bits): ImageColorSpace
    {
        return match ($photometric) {
            self::RGB => ImageColorSpace::rgb(),
            self::SEPARATED => ImageColorSpace::cmyk(),
            self::PALETTE => ImageColorSpace::indexed($this->palette($bits)),
            default => ImageColorSpace::gray(),
        };
    }

    private function decompress(string $strip, int $compression): string
    {
        return match ($compression) {
            self::LZW => Lzw::decode($strip, maxBytes: $this->limits->maxDecodedBytes),
            self::DEFLATE, self::ADOBE_DEFLATE => Flate::decode($strip, $this->limits->maxDecodedBytes),
            self::PACKBITS => self::packBits($strip),
            default => $strip,
        };
    }

    private function dpi(int $tag): float
    {
        $value = $this->file->value($this->ifd, $tag);
        $unit  = $this->int(self::UNIT, 2);
        if ((! is_float($value) && ! is_int($value)) || $value <= 0 || (2 !== $unit && 3 !== $unit)) {
            return 72.0;
        }

        return 3 === $unit ? $value * 2.54 : (float) $value;
    }

    /** Single-strip CCITT fax data, passed through. */
    private function fax(int $width, int $height, int $bits, int $samples, int $compression): ?ImageData
    {
        $strips = $this->strips();
        if (1 !== $bits || 1 !== $samples || null === $strips || 1 !== count($strips)) {
            return null;
        }
        $options = $this->int(self::T4_OPTIONS);
        $k       = $options & 1;
        if (self::CCITT_GROUP_4 === $compression) {
            $k = -1;
        }
        $parms = Dictionary::of([
            'K'                => $k,
            'Columns'          => $width,
            'Rows'             => $height,
            'BlackIs1'         => self::BLACK_IS_ZERO === $this->int(self::PHOTOMETRIC) ? true : null,
            'EncodedByteAlign' => 3 === $compression && $options & 4 ? true : null,
        ]);

        return new ImageData(
            $width,
            $height,
            1,
            ImageColorSpace::gray(),
            $strips[0],
            'CCITTFaxDecode',
            $parms,
            dpiX: $this->dpi(self::X_RESOLUTION),
            dpiY: $this->dpi(self::Y_RESOLUTION),
            orientation: $this->orientation(),
        );
    }

    private function int(int $tag, int $default = 0): int
    {
        $value = $this->file->value($this->ifd, $tag, $default);

        return is_int($value) ? $value : $default;
    }

    /**
     * Whether the samples are read natively, and whether they carry alpha: null when not, else
     * true for colour samples followed by an unassociated alpha sample.
     */
    private function layout(int $photometric, int $bits, int $samples, int $compression): ?bool
    {
        $colours = match ($photometric) {
            self::WHITE_IS_ZERO, self::BLACK_IS_ZERO, self::PALETTE => 1,
            self::RGB => 3,
            self::SEPARATED => 1 === $this->int(self::INK_SET, 1) ? 4 : 0,
            default => 0,
        };
        $extra     = $this->file->values($this->ifd, self::EXTRA_SAMPLES);
        $alpha     = $samples === ($colours + 1) && 2 === ($extra[0] ?? 0);
        $predictor = $this->int(self::PREDICTOR, 1);
        $supported = 0 !== $colours
        && ($samples === $colours || ($alpha && $bits >= 8))
        && in_array(
            $compression,
            [self::NONE, self::LZW, self::DEFLATE, self::ADOBE_DEFLATE, self::PACKBITS],
            strict: true,
        )
        && (1 === $predictor || (2 === $predictor && 8 === $bits));

        return $supported ? $alpha : null;
    }

    private function orientation(): int
    {
        $orientation = $this->int(self::ORIENTATION, 1);

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /** RGB triplets from the 16-bit colour map: all reds, then greens, then blues. */
    private function palette(int $bits): string
    {
        $map     = $this->file->values($this->ifd, self::COLOR_MAP);
        $entries = 1 << $bits;
        if (count($map) < (3 * $entries)) {
            throw new ParseException('TIFF palette image has no complete colour map.');
        }
        $palette = '';
        for ($i = 0; $i < $entries; ++$i) {
            foreach ([0, $entries, 2 * $entries] as $channel) {
                $palette .= chr((int) ($map[$channel + $i] ?? 0) >> 8);
            }
        }

        return $palette;
    }

    /** The strips decompressed, unpredicted and in big-endian byte order. */
    private function pixels(int $width, int $height, int $bits, int $samples, int $compression): string
    {
        $strips = $this->strips() ?? throw new ParseException('TIFF strips lie outside the file.');
        $raw    = '';
        foreach ($strips as $strip) {
            $raw .= $this->decompress($strip, $compression);
        }
        $rowBytes = intdiv(($width * $samples * $bits) + 7, num2: 8);
        if (strlen($raw) < ($rowBytes * $height)) {
            throw new ParseException('TIFF image data is truncated.');
        }
        $raw = substr($raw, offset: 0, length: $rowBytes * $height);
        if (2 === $this->int(self::PREDICTOR, 1)) {
            $raw = Predictor::decode($raw, 2, $samples, $bits, $width);
        }
        if (16 === $bits && $this->file->isLittleEndian()) {
            $raw = preg_replace('/(.)(.)/s', replacement: '$2$1', subject: $raw) ?? $raw;
        }

        return $raw;
    }

    private function read(): ?ImageData
    {
        $width  = $this->int(self::WIDTH);
        $height = $this->int(self::HEIGHT);
        if ($width < 1 || $height < 1) {
            throw new ParseException('TIFF image has no pixels.');
        }
        $this->limits->assertImagePixels($width, $height);
        $bitsList = $this->file->values($this->ifd, self::BITS);
        $bitsList = array_unique([] === $bitsList ? [1] : $bitsList);
        $bits     = $bitsList[0] ?? 1;
        $samples  = $this->int(self::SAMPLES, 1);
        if (
            1 !== count($bitsList)
            || ! in_array($bits, [1, 2, 4, 8, 16], strict: true)
            || $this->file->has($this->ifd, self::TILE_WIDTH)
            || 1 !== $this->int(self::PLANAR, 1)
            || 1 !== $this->int(self::FILL_ORDER, 1)
        ) {
            return null;
        }
        $compression = $this->int(self::COMPRESSION, self::NONE);
        if (self::CCITT_GROUP_3 === $compression || self::CCITT_GROUP_4 === $compression) {
            return $this->fax($width, $height, (int) $bits, $samples, $compression);
        }

        return $this->samples($width, $height, (int) $bits, $samples, $compression);
    }

    /** Uncompressed, LZW, Deflate or PackBits strips, decoded to samples. */
    private function samples(int $width, int $height, int $bits, int $samples, int $compression): ?ImageData
    {
        $photometric = $this->int(self::PHOTOMETRIC, -1);
        $alpha       = $this->layout($photometric, $bits, $samples, $compression);
        if (null === $alpha) {
            return null;
        }
        $raw      = $this->pixels($width, $height, $bits, $samples, $compression);
        $softMask = null;
        if ($alpha) {
            [$raw, $softMask] = AlphaSplit::split($raw, $samples, intdiv($bits, num2: 8));
            $bits = 8;
        }

        return new ImageData(
            $width,
            $height,
            $bits,
            $this->colorSpace($photometric, $bits),
            $raw,
            decode: self::WHITE_IS_ZERO === $photometric ? [1, 0] : null,
            softMask: $softMask,
            iccProfile: $this->file->raw($this->ifd, self::ICC_PROFILE),
            dpiX: $this->dpi(self::X_RESOLUTION),
            dpiY: $this->dpi(self::Y_RESOLUTION),
            orientation: $this->orientation(),
            photoshop: $this->file->raw($this->ifd, self::PHOTOSHOP),
        );
    }

    /** @return list<string>|null the strips' bytes, or null when one lies outside the file */
    private function strips(): ?array
    {
        $offsets = $this->file->values($this->ifd, self::STRIP_OFFSETS);
        $counts  = $this->file->values($this->ifd, self::STRIP_COUNTS);
        if ([] === $offsets || count($offsets) !== count($counts)) {
            throw new ParseException('TIFF image has no strips.');
        }
        $strips = [];
        foreach ($offsets as $i => $offset) {
            $strip = $this->file->bytes((int) $offset, (int) ($counts[$i] ?? 0));
            if (null === $strip) {
                return null;
            }
            $strips[] = $strip;
        }

        return $strips;
    }
}
