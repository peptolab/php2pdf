<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Override;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Canvas\FillRule;
use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Color\ColourPolicyInterface;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Objects;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Geometry\Matrix;

use function md5;

/**
 * An image written once to a document as an image XObject and placed any number of times.
 *
 * @api
 */
final class Image implements PlaceableInterface
{
    private readonly Reference $reference;

    /**
     * @param Reference|null $reference the image XObject when it is written already, for another
     *                                  placement of the same pixels
     *
     * @internal
     */
    public function __construct(
        private readonly Objects $objects,
        private readonly ColourPolicyInterface $colour,
        private readonly ImageData $data,
        ?Reference $reference = null,
    ) {
        $this->reference = $reference ?? self::write($objects, $data);
    }

    /**
     * Writes an image's pixels, and its soft mask, as an image XObject: what any placement of
     * the same pixels shares.
     *
     * @internal
     */
    public static function write(Objects $objects, ImageData $data): Reference
    {
        $writer = $objects;
        $raster = $data->raster;
        $mask   = $data->softMask;

        $icc = null;
        if (null !== $raster->iccProfile && ! $raster->colorSpace->isIndexed()) {
            $profile = $raster->iccProfile;
            $n       = $raster->colorSpace->components;
            $icc     = $objects->shared(
                'iccstream:' . md5($profile),
                static fn(): Stream => new Stream(Dictionary::of(['N' => $n]), $profile),
            );
        }

        $dict = Dictionary::of([
            'Type'             => new Name('XObject'),
            'Subtype'          => new Name('Image'),
            'Width'            => $raster->width,
            'Height'           => $raster->height,
            'ColorSpace'       => $raster->colorSpace->toPdf($icc),
            'BitsPerComponent' => $raster->bitsPerComponent,
            'Filter'           => null === $raster->filter ? null : new Name($raster->filter),
            'DecodeParms'      => $raster->decodeParms,
            'Decode'           => $raster->decode,
            'Mask'             => $raster->colorKeyMask,
            'SMaskInData'      => $raster->smaskInData ? 1 : null,
        ]);
        if (null !== $mask) {
            $dict->set(
                'SMask',
                $writer->add(
                    new Stream(Dictionary::of([
                        'Type'             => new Name('XObject'),
                        'Subtype'          => new Name('Image'),
                        'Width'            => $raster->width,
                        'Height'           => $raster->height,
                        'ColorSpace'       => new Name('DeviceGray'),
                        'BitsPerComponent' => $mask->bits,
                    ]), $mask->data),
                ),
            );
        }

        return $writer->add(new Stream($dict, $raster->data, compress: null === $raster->filter));
    }

    /**
     * Maps the stored image's unit square onto the unit square it is shown in, for an EXIF
     * orientation: mirrored, turned a half or quarter turn, or both.
     */
    private static function orientation(int $orientation): Matrix
    {
        return match ($orientation) {
            2       => new Matrix(-1.0, 0.0, 0.0, 1.0, 1.0, 0.0),
            3       => new Matrix(-1.0, 0.0, 0.0, -1.0, 1.0, 1.0),
            4       => new Matrix(1.0, 0.0, 0.0, -1.0, 0.0, 1.0),
            5       => new Matrix(0.0, -1.0, -1.0, 0.0, 1.0, 1.0),
            6       => new Matrix(0.0, -1.0, 1.0, 0.0, 0.0, 1.0),
            7       => new Matrix(0.0, 1.0, 1.0, 0.0, 0.0, 0.0),
            8       => new Matrix(0.0, 1.0, -1.0, 0.0, 1.0, 0.0),
            default => Matrix::identity(),
        };
    }

    public function dpiX(): float
    {
        return $this->data->dpiX;
    }

    public function dpiY(): float
    {
        return $this->data->dpiY;
    }

    #[Override]
    public function drawAt(Canvas $canvas, Matrix $matrix, Dimensions $size): void
    {
        $canvas->objects()->assertOwns($this->objects, 'An image');
        $this->colour->assertOutputIntent();
        if (null === $this->data->raster->iccProfile || $this->data->raster->colorSpace->isIndexed()) {
            $this->colour->assertDeviceColour($this->data->raster->colorSpace->device, 'images');
        }
        $placement = self::orientation($this->data->orientation)
            ->then(Matrix::scaling($size->width, $size->height))
            ->then($matrix);
        $clip = $this->data->clip;
        if (null === $clip) {
            $canvas->drawXObject($this->reference, $placement);

            return;
        }
        $canvas->save()
            ->concat($placement)
            ->path($clip)
            ->clip(FillRule::EvenOdd)
            ->drawXObject($this->reference, Matrix::identity());
        $canvas->restore();
    }

    public function hasTransparency(): bool
    {
        return $this->data->hasTransparency();
    }

    public function heightPx(): int
    {
        return $this->data->raster->height;
    }

    #[Override]
    public function naturalSize(?float $dpi = null): Dimensions
    {
        $width  = ($this->data->raster->width * 72.0) / ($dpi ?? $this->data->dpiX);
        $height = ($this->data->raster->height * 72.0) / ($dpi ?? $this->data->dpiY);

        return $this->data->isTransposed() ? new Dimensions($height, $width) : new Dimensions($width, $height);
    }

    /** @internal */
    public function reference(): Reference
    {
        return $this->reference;
    }

    public function widthPx(): int
    {
        return $this->data->raster->width;
    }
}
