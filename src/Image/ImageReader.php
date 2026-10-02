<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\PdfException;

use function file_get_contents;
use function is_file;
use function is_readable;
use function sprintf;

/**
 * Reads an image with the default decoders (see ImageDecoders): each checks the pixel limit as
 * soon as it has read the image's header, before any pixels are decoded, and the result is
 * checked again here, so no format gets past the limit.
 *
 * @api
 */
final class ImageReader
{
    /**
     * @param ImageOptions|null $options the page of a multi-page file (TIFF, GIF), and whether a
     *                                   conversion that loses data is accepted
     * @param string|null       $name    the file the data came from, for error messages
     *
     * @throws PdfException for unreadable data, a missing page, an image over the limits, or a
     *                      conversion that would lose data when that is not accepted
     */
    public static function read(
        string $data,
        ?Limits $limits = null,
        ?ImageOptions $options = null,
        ?string $name = null,
    ): ImageData {
        $limits ??= Limits::default();
        $image  = ImageDecoders::default()->read($data, $limits, $options ?? new ImageOptions(), $name);
        $limits->assertImagePixels($image->raster->width, $image->raster->height);

        return $image;
    }

    /** @throws IoException */
    public static function readFile(string $path, ?Limits $limits = null, ?ImageOptions $options = null): ImageData
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new IoException(sprintf('Image file "%s" not found.', $path));
        }
        $data = file_get_contents($path);
        if (false === $data) {
            throw new IoException(sprintf('Cannot read image file "%s".', $path));
        }

        return self::read($data, $limits, $options, $path);
    }
}
