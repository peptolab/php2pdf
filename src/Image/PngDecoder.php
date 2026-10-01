<?php

declare(strict_types=1);

namespace PhpPdf\Image;

use PhpPdf\Core\Filter\Flate;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Exception\PdfException;

use function array_slice;
use function array_values;
use function count;
use function implode;
use function intdiv;
use function max;
use function ord;
use function sprintf;
use function str_pad;
use function str_split;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function unpack;

/**
 * Reads PNG files. Opaque, non-interlaced images are passed through with PNG predictors so no
 * pixel is touched; images with alpha, palette transparency or interlacing are decoded and the
 * alpha channel split into a soft mask.
 *
 * @internal
 */
final class PngDecoder
{
    private const string SIGNATURE = "\x89PNG\r\n\x1A\n";

    public static function decode(string $data): ImageData
    {
        if (! str_starts_with($data, self::SIGNATURE)) {
            throw new PdfException('Not a PNG file.');
        }
        $chunks = self::chunks($data);
        [$width, $height, $depth, $type, $interlace] = self::header(
            $chunks['IHDR'][0] ?? throw new PdfException('PNG has no IHDR chunk.'),
        );
        $channels = match ($type) {
            0, 3    => 1,
            2       => 3,
            4       => 2,
            6       => 4,
            default => throw new PdfException(sprintf('Unsupported PNG colour type %d.', $type)),
        };
        [$palette, $trns, $idat] = self::pixelChunks($chunks, $type);
        $colorSpace = match ($type) {
            0, 4    => ImageColorSpace::gray(),
            3       => ImageColorSpace::indexed($palette ?? ''),
            default => ImageColorSpace::rgb(),
        };
        $meta = [
            'icc' => self::iccProfile($chunks['iCCP'][0] ?? null),
            'dpi' => self::resolution($chunks['pHYs'][0] ?? null),
        ];

        $needsDecode = 1 === $interlace || 4 === $type || 6 === $type || (3 === $type && null !== $trns);
        if (! $needsDecode) {
            return new ImageData(
                $width,
                $height,
                $depth,
                $colorSpace,
                $idat,
                'FlateDecode',
                Dictionary::of([
                    'Predictor'        => 15,
                    'Colors'           => $channels,
                    'BitsPerComponent' => $depth,
                    'Columns'          => $width,
                ]),
                colorKeyMask: self::colorKey($trns, $type, $depth),
                iccProfile: $meta['icc'],
                dpiX: $meta['dpi'][0],
                dpiY: $meta['dpi'][1],
            );
        }

        $factor  = 3 === $type || $depth >= 8 ? 1 : intdiv(255, (1 << $depth) - 1);
        $samples = PngScanlines::decode(Flate::decode($idat), $width, $height, $depth, $channels, $interlace, $factor);
        [$color, $alpha] = self::split($samples, $channels, $depth > 8 ? 2 : 1, $type, $trns);

        return new ImageData(
            $width,
            $height,
            4 === $type || 6 === $type ? 8 : max(8, $depth),
            $colorSpace,
            Flate::encode($color),
            'FlateDecode',
            softMask: $alpha,
            iccProfile: $meta['icc'],
            dpiX: $meta['dpi'][0],
            dpiY: $meta['dpi'][1],
        );
    }

    /** @return array<string, list<string>> chunk type => data blocks in file order */
    private static function chunks(string $data): array
    {
        $chunks = [];
        $offset = 8;
        $length = strlen($data);
        while (($offset + 8) <= $length) {
            $size = (int) unpack('N', $data, $offset)[1];
            $type = substr($data, $offset + 4, length: 4);
            if (($offset + 12 + $size) > $length) {
                throw new PdfException(sprintf('PNG chunk "%s" is truncated.', $type));
            }
            $chunks[$type][] = substr($data, $offset + 8, $size);
            if ('IEND' === $type) {
                break;
            }
            $offset += 12 + $size;
        }

        return $chunks;
    }

    /** @return list<int>|null */
    private static function colorKey(?string $trns, int $type, int $depth): ?array
    {
        if (null === $trns || (0 !== $type && 2 !== $type)) {
            return null;
        }
        $values = array_values(unpack('n*', $trns));
        $needed = 2 === $type ? 3 : 1;
        if (count($values) < $needed) {
            return null;
        }
        $mask = [];
        foreach (array_slice($values, offset: 0, length: $needed) as $v) {
            $v      = $depth < 16 ? $v & ((1 << $depth) - 1) : $v;
            $mask[] = $v;
            $mask[] = $v;
        }

        return $mask;
    }

    /** @return array{0: int, 1: int, 2: int, 3: int, 4: int} width, height, depth, colour type, interlace */
    private static function header(string $ihdr): array
    {
        if (strlen($ihdr) < 13) {
            throw new PdfException('PNG IHDR chunk is truncated.');
        }
        $v      = unpack('Nwidth/Nheight/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $ihdr);
        $width  = (int) ($v['width'] ?? 0);
        $height = (int) ($v['height'] ?? 0);
        if ($width < 1 || $height < 1) {
            throw new PdfException('PNG has no pixels.');
        }

        return [$width, $height, (int) ($v['depth'] ?? 8), (int) ($v['type'] ?? 0), (int) ($v['interlace'] ?? 0)];
    }

    private static function iccProfile(?string $iccp): ?string
    {
        if (null === $iccp) {
            return null;
        }
        $null = strpos($iccp, needle: "\0");
        if (false === $null || strlen($iccp) < ($null + 3)) {
            return null;
        }
        try {
            return Flate::decode(substr($iccp, offset: $null + 2));
        } catch (PdfException) {
            return null;
        }
    }

    private static function paletteAlpha(string $indices, string $trns): string
    {
        $table = str_pad($trns, length: 256, pad_string: "\xFF");
        $alpha = '';
        foreach (str_split($indices) as $index) {
            $alpha .= $table[ord($index)];
        }

        return $alpha;
    }

    /**
     * The palette, transparency and concatenated image data, validated for the colour type.
     *
     * @param array<string, list<string>> $chunks
     *
     * @return array{0: string|null, 1: string|null, 2: string}
     */
    private static function pixelChunks(array $chunks, int $type): array
    {
        $palette = $chunks['PLTE'][0] ?? null;
        $idat    = implode('', $chunks['IDAT'] ?? []);
        if ('' === $idat) {
            throw new PdfException('PNG has no image data.');
        }
        if (3 === $type && null === $palette) {
            throw new PdfException('Indexed PNG has no palette.');
        }

        return [$palette, $chunks['tRNS'][0] ?? null, $idat];
    }

    /** @return array{0: float, 1: float} */
    private static function resolution(?string $phys): array
    {
        if (null === $phys || strlen($phys) < 9) {
            return [72.0, 72.0];
        }
        $v = unpack('Nx/Ny/Cunit', $phys);
        $x = (int) ($v['x'] ?? 0);
        $y = (int) ($v['y'] ?? 0);
        if (1 !== (int) ($v['unit'] ?? 0) || 0 === $x || 0 === $y) {
            return [72.0, 72.0];
        }

        return [$x * 0.0254, $y * 0.0254];
    }

    /**
     * Separates colour samples from alpha. Returns the colour bytes (8-bit when alpha is present,
     * otherwise the native depth) and the 8-bit alpha mask when there is one.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function split(string $samples, int $channels, int $bytes, int $type, ?string $trns): array
    {
        if (3 === $type) {
            return [$samples, null === $trns ? null : self::paletteAlpha($samples, $trns)];
        }
        if (4 !== $type && 6 !== $type) {
            return [$samples, null];
        }

        // Interleaved colour and alpha; keep the high byte of 16-bit samples.
        $colorChannels = $channels - 1;
        $pixelBytes    = $channels * $bytes;
        $color         = '';
        $alpha         = '';
        $count         = intdiv(strlen($samples), $pixelBytes);
        for ($i = 0; $i < $count; ++$i) {
            $base = $i * $pixelBytes;
            for ($c = 0; $c < $colorChannels; ++$c) {
                $color .= $samples[$base + ($c * $bytes)];
            }
            $alpha .= $samples[$base + ($colorChannels * $bytes)];
        }

        return [$color, $alpha];
    }
}
