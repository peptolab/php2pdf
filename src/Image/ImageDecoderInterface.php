<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\PdfException;

/**
 * Reads one image format natively. A decoder that recognises a file but not its layout says
 * what converting it would have to keep, and the file is converted to PNG instead.
 *
 * @api
 */
interface ImageDecoderInterface
{
    /** Whether the data is in this decoder's format, from its first bytes. */
    public function accepts(string $data): bool;

    /**
     * The image on the options' page, checked against the limits as soon as its size is known; or,
     * when its layout is not read natively, what converting it would have to keep.
     *
     * @throws PdfException for malformed data, a missing page or an image over the limits
     */
    public function decode(string $data, Limits $limits, ImageOptions $options): ImageData|ImageTraits;

    /** Whether files in this format can hold more than one image. */
    public function hasPages(): bool;

    /** The format's name, as messages give it. */
    public function name(): string;
}
