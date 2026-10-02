<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

/**
 * An image's alpha samples, uncompressed, at the image's size: written as its soft mask.
 *
 * @api
 */
final readonly class SoftMask
{
    /** @param int $bits bits per sample: 8 or 16 */
    public function __construct(
        public string $data,
        public int $bits = 8,
    ) {}
}
