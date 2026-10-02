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

    /** @internal */
    public function __construct(
        private readonly Objects $objects,
        private readonly ColourPolicyInterface $colour,
        private readonly ImageData $data,
    ) {
        $this->reference = $this->write();
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
        if (null === $this->data->iccProfile || $this->data->colorSpace->isIndexed()) {
            $this->colour->assertDeviceColour($this->data->colorSpace->device, 'images');
        }
        $placement = self::orientation($this->data->orientation)
            ->then(Matrix::scaling($size->width, $size->height))
            ->then($matrix);
        $clip = $this->data->clip;
        if (null === $clip) {
            $canvas->drawXObject($this->reference, $placement);

            return;
        }
        $canvas->save()->concat($placement);
        $clip->addTo($canvas);
        $canvas->clip(FillRule::EvenOdd)->drawXObject($this->reference, Matrix::identity());
        $canvas->restore();
    }

    public function hasTransparency(): bool
    {
        return $this->data->hasTransparency();
    }

    public function heightPx(): int
    {
        return $this->data->height;
    }

    #[Override]
    public function naturalSize(?float $dpi = null): Dimensions
    {
        $width  = ($this->data->width * 72.0) / ($dpi ?? $this->data->dpiX);
        $height = ($this->data->height * 72.0) / ($dpi ?? $this->data->dpiY);

        return $this->data->isTransposed() ? new Dimensions($height, $width) : new Dimensions($width, $height);
    }

    /** @internal */
    public function reference(): Reference
    {
        return $this->reference;
    }

    public function widthPx(): int
    {
        return $this->data->width;
    }

    private function write(): Reference
    {
        $writer = $this->objects;
        $data   = $this->data;

        $icc = null;
        if (null !== $data->iccProfile && ! $data->colorSpace->isIndexed()) {
            $profile = $data->iccProfile;
            $n       = $data->colorSpace->components;
            $icc     = $this->objects->shared(
                'iccstream:' . md5($profile),
                static fn(): Stream => new Stream(Dictionary::of(['N' => $n]), $profile),
            );
        }

        $dict = Dictionary::of([
            'Type'             => new Name('XObject'),
            'Subtype'          => new Name('Image'),
            'Width'            => $data->width,
            'Height'           => $data->height,
            'ColorSpace'       => $data->colorSpace->toPdf($icc),
            'BitsPerComponent' => $data->bitsPerComponent,
            'Filter'           => null === $data->filter ? null : new Name($data->filter),
            'DecodeParms'      => $data->decodeParms,
            'Decode'           => $data->decode,
            'Mask'             => $data->colorKeyMask,
            'SMaskInData'      => $data->smaskInData ? 1 : null,
        ]);
        if (null !== $data->softMask) {
            $dict->set(
                'SMask',
                $writer->add(
                    new Stream(Dictionary::of([
                        'Type'             => new Name('XObject'),
                        'Subtype'          => new Name('Image'),
                        'Width'            => $data->width,
                        'Height'           => $data->height,
                        'ColorSpace'       => new Name('DeviceGray'),
                        'BitsPerComponent' => $data->softMaskBits,
                    ]), $data->softMask),
                ),
            );
        }

        return $writer->add(new Stream($dict, $data->data, compress: null === $data->filter));
    }
}
