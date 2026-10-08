<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Php2Pdf\Core\FileReader;
use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\PdfException;

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
     * @param ImageDecoders|null $decoders the decoders to read it with: the default ones unless set
     *
     * @throws PdfException for unreadable data, a missing page, an image over the limits, or a
     *                      conversion that would lose data when that is not accepted
     */
    public static function read(
        string $data,
        ?Limits $limits = null,
        ?ImageOptions $options = null,
        ?string $name = null,
        ?ImageDecoders $decoders = null,
    ): ImageData {
        $limits ??= Limits::default();
        $image  = ($decoders ?? ImageDecoders::default())->read($data, $limits, $options ?? new ImageOptions(), $name);
        $limits->assertImagePixels($image->raster->width, $image->raster->height);

        return $image;
    }

    /** @throws IoException */
    public static function readFile(string $path, ?Limits $limits = null, ?ImageOptions $options = null): ImageData
    {
        return self::read(FileReader::read($path, 'image file'), $limits, $options, $path);
    }
}
