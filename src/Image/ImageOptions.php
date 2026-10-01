<?php

declare(strict_types=1);

namespace PhpPdf\Image;

use PhpPdf\Exception\InvalidArgumentException;

use function sprintf;

/**
 * How an image file is loaded: the resolution to size it at instead of the file's own, the page
 * of a multi-page file (TIFF, GIF), and whether to follow the EXIF orientation.
 *
 * @api
 */
final readonly class ImageOptions
{
    /**
     * @param float|null $dpi         the resolution for its natural size, instead of the file's
     * @param int        $page        the 1-based page of a multi-page file
     * @param bool       $orientation whether to show a JPEG the way its EXIF orientation says
     */
    public function __construct(
        public ?float $dpi = null,
        public int $page = 1,
        public bool $orientation = true,
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
        return sprintf('%s/%d/%d', $this->dpi ?? '-', $this->page, (int) $this->orientation);
    }
}
