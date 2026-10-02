<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Php2Pdf\Exception\InvalidArgumentException;

use function is_string;
use function sprintf;

/**
 * How an image file is loaded: the resolution to size it at instead of the file's own, the page
 * of a multi-page file (TIFF, GIF), whether to follow the EXIF orientation, and whether a
 * conversion that loses data is accepted for a file that is not read natively.
 *
 * @api
 */
final readonly class ImageOptions
{
    /**
     * @param float|null $dpi         the resolution for its natural size, instead of the file's
     * @param int        $page        the 1-based page of a multi-page file
     * @param bool       $orientation  whether to show a JPEG the way its EXIF orientation says
     * @param bool|string $clippingPath clip to a Photoshop path: true for the one marked as the
     *                                  clipping path, a name for another, false for none
     * @param bool        $allowLossyConversion accept converting a file that is not read natively
     *                                  through ext-imagick or ext-gd even when the conversion
     *                                  loses data: CMYK or CIELab turned to RGB, an ICC profile
     *                                  dropped, samples cut to fewer bits or alpha lost
     */
    public function __construct(
        public ?float $dpi = null,
        public int $page = 1,
        public bool $orientation = true,
        public bool|string $clippingPath = false,
        public bool $allowLossyConversion = false,
    ) {
        if (null !== $dpi && $dpi <= 0.0) {
            throw new InvalidArgumentException(sprintf('An image resolution must be positive, %s given.', $dpi));
        }
        if ($page < 1) {
            throw new InvalidArgumentException(sprintf('Image pages are numbered from 1, %d given.', $page));
        }
    }

    /** A key telling loads of the same data with different options apart. */
    public function key(): string
    {
        return sprintf(
            '%s/%d/%d/%s/%d',
            $this->dpi ?? '-',
            $this->page,
            (int) $this->orientation,
            $this->clippingKey(),
            (int) $this->allowLossyConversion,
        );
    }

    private function clippingKey(): string
    {
        if (is_string($this->clippingPath)) {
            return "={$this->clippingPath}";
        }

        return $this->clippingPath ? '*' : '-';
    }
}
