<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Php2Pdf\Core\Object\Dictionary;

/**
 * An image's samples and what reading them takes: what is written as the image XObject.
 *
 * @api
 */
final readonly class Raster
{
    /**
     * @param string          $data         sample data, already encoded with $filter when one is set
     * @param string|null     $filter       DCTDecode, JPXDecode, FlateDecode, CCITTFaxDecode,
     *                                      JBIG2Decode, or null for raw samples
     * @param Dictionary|null $decodeParms  the filter's parameters
     * @param list<int>|null  $decode       a /Decode array, e.g. to invert Adobe CMYK JPEGs
     * @param list<int>|null  $colorKeyMask /Mask ranges for colour-key transparency
     * @param string|null     $iccProfile   an embedded ICC profile matching the colour space
     * @param bool            $smaskInData  whether JPEG 2000 data carries its own alpha channel
     * @param string|null     $jbig2Globals the JBIG2 segments shared by every page of the file the
     *                                      data came from, written as its JBIG2Globals stream
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
        public ?array $colorKeyMask = null,
        public ?string $iccProfile = null,
        public bool $smaskInData = false,
        public ?string $jbig2Globals = null,
    ) {}
}
