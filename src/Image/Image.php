<?php

declare(strict_types=1);

namespace PhpPdf\Image;

use Override;
use PhpPdf\Canvas\Canvas;
use PhpPdf\Canvas\PlaceableInterface;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Document\Dimensions;
use PhpPdf\Document\Document;
use PhpPdf\Geometry\Matrix;

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
        Document $document,
        private readonly ImageData $data,
    ) {
        $this->reference = $this->write($document);
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
        $canvas->document()->assertOutputIntent();
        if (null === $this->data->iccProfile || $this->data->colorSpace->isIndexed()) {
            $canvas->document()->assertDeviceColour($this->data->colorSpace->device, 'images');
        }
        $canvas->drawXObject($this->reference, Matrix::scaling($size->width, $size->height)->then($matrix));
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
        return new Dimensions(
            ($this->data->width * 72.0) / ($dpi ?? $this->data->dpiX),
            ($this->data->height * 72.0) / ($dpi ?? $this->data->dpiY),
        );
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

    private function write(Document $document): Reference
    {
        $writer = $document->writer();
        $data   = $this->data;

        $icc = null;
        if (null !== $data->iccProfile && ! $data->colorSpace->isIndexed()) {
            $profile = $data->iccProfile;
            $n       = $data->colorSpace->components;
            $icc     = $document->shared(
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
                        'BitsPerComponent' => 8,
                    ]), $data->softMask),
                ),
            );
        }

        return $writer->add(new Stream($dict, $data->data, compress: null === $data->filter));
    }
}
