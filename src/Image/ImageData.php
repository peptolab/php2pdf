<?php

declare(strict_types=1);

namespace PhpPdf\Image;

use PhpPdf\Core\Object\Dictionary;

/**
 * A decoded (or passed-through) image ready to be written as an image XObject.
 *
 * @api
 */
final readonly class ImageData
{
    /**
     * @param string          $data         sample data, already encoded with $filter when one is set
     * @param string|null     $filter       DCTDecode, FlateDecode, or null for raw samples
     * @param Dictionary|null $decodeParms  predictor parameters for FlateDecode
     * @param list<int>|null  $decode       a /Decode array, e.g. to invert Adobe CMYK JPEGs
     * @param string|null     $softMask     8-bit alpha samples, one byte per pixel, uncompressed
     * @param list<int>|null  $colorKeyMask /Mask ranges for colour-key transparency
     * @param string|null     $iccProfile   an embedded ICC profile matching the colour space
     */
    public function __construct(
        public int $width,
        public int $height,
        public int $bitsPerComponent,
        public ImageColorSpace $colorSpace,
        public string $data,
        public ?string $filter = null,
        public ?Dictionary $decodeParms = null,
        public ?array $decode = null,
        public ?string $softMask = null,
        public ?array $colorKeyMask = null,
        public ?string $iccProfile = null,
        public float $dpiX = 72.0,
        public float $dpiY = 72.0,
    ) {}

    public function hasTransparency(): bool
    {
        return null !== $this->softMask || null !== $this->colorKeyMask;
    }
}
