<?php

declare(strict_types=1);

namespace PhpPdf\Document;

use PhpPdf\Canvas\Canvas;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Exception\PdfException;
use PhpPdf\Geometry\Box;

/**
 * A page being drawn. Coordinates are in points with the origin at the bottom-left corner, or at
 * the top-left corner with y growing downwards when the page is top-down. Page boxes are reported
 * and set in the same coordinate mode.
 *
 * @api
 */
final class Page
{
    private readonly Canvas $canvas;
    private readonly Resources $resources;
    private readonly Box $mediaBox;
    private Box|null $cropBox = null;
    private Box|null $bleedBox = null;
    private Box|null $trimBox = null;
    private Box|null $artBox = null;
    private int $rotation = 0;
    private bool $finished = false;

    /** @internal */
    public function __construct(
        private readonly Document $document,
        Dimensions $size,
        private readonly bool $topDown,
        private readonly int $number,
    ) {
        $this->mediaBox  = new Box(0.0, 0.0, $size->width, $size->height);
        $this->resources = new Resources();
        $this->canvas    = new Canvas($document, $this->resources, $size->height, $topDown);
    }

    public function artBox(): Box
    {
        return $this->artBox ?? $this->cropBox();
    }

    public function bleedBox(): Box
    {
        return $this->bleedBox ?? $this->cropBox();
    }

    public function canvas(): Canvas
    {
        $this->assertOpen();

        return $this->canvas;
    }

    public function cropBox(): Box
    {
        return $this->cropBox ?? $this->mediaBox;
    }

    public function document(): Document
    {
        return $this->document;
    }

    /**
     * Writes the content stream and page dictionary.
     *
     * @internal
     */
    public function finish(Reference $parent): Reference
    {
        if ($this->finished) {
            throw new PdfException(sprintf('Page %d has already been finished.', $this->number));
        }
        $this->finished = true;

        $writer   = $this->document->writer();
        $contents = $writer->add(Stream::of($this->canvas->finish()));

        $dict = Dictionary::of([
            'Type' => new Name('Page'),
            'Parent' => $parent,
            'MediaBox' => $this->mediaBox->toRect(),
            'CropBox' => $this->pdfRect($this->cropBox),
            'BleedBox' => $this->pdfRect($this->bleedBox),
            'TrimBox' => $this->pdfRect($this->trimBox),
            'ArtBox' => $this->pdfRect($this->artBox),
            'Rotate' => 0 === $this->rotation ? null : $this->rotation,
            'Resources' => $this->resources->toDictionary(),
            'Contents' => $contents,
        ]);

        return $writer->add($dict);
    }

    public function height(): float
    {
        return $this->mediaBox->height;
    }

    public function isTopDown(): bool
    {
        return $this->topDown;
    }

    public function mediaBox(): Box
    {
        return $this->mediaBox;
    }

    /** The 1-based page number. */
    public function number(): int
    {
        return $this->number;
    }

    /** @internal */
    public function resources(): Resources
    {
        return $this->resources;
    }

    public function rotation(): int
    {
        return $this->rotation;
    }

    public function setArtBox(Box|null $box): self
    {
        $this->artBox = $this->clamp($box);

        return $this;
    }

    public function setBleedBox(Box|null $box): self
    {
        $this->bleedBox = $this->clamp($box);

        return $this;
    }

    public function setCropBox(Box|null $box): self
    {
        $this->cropBox = $this->clamp($box);

        return $this;
    }

    /** Display rotation in degrees: 0, 90, 180 or 270 (clockwise). */
    public function setRotation(int $degrees): self
    {
        $degrees = (($degrees % 360) + 360) % 360;
        if (0 !== $degrees % 90) {
            throw new InvalidArgumentException('Page rotation must be a multiple of 90 degrees.');
        }
        $this->rotation = $degrees;

        return $this;
    }

    public function setTrimBox(Box|null $box): self
    {
        $this->trimBox = $this->clamp($box);

        return $this;
    }

    /**
     * Converts a box from the page's coordinate mode to PDF (bottom-left) space.
     *
     * @internal
     */
    public function toPdfBox(Box $box): Box
    {
        return $this->topDown ? new Box($box->x, $this->height() - $box->far(), $box->width, $box->height) : $box;
    }

    public function trimBox(): Box
    {
        return $this->trimBox ?? $this->cropBox();
    }

    public function width(): float
    {
        return $this->mediaBox->width;
    }

    private function assertOpen(): void
    {
        if ($this->finished) {
            throw new PdfException(sprintf('Page %d has been written and can no longer be drawn on.', $this->number));
        }
    }

    /** @return list<float>|null */
    private function pdfRect(Box|null $box): array|null
    {
        return null === $box ? null : $this->toPdfBox($box)->toRect();
    }

    private function clamp(Box|null $box): Box|null
    {
        if (null === $box) {
            return null;
        }
        $clamped = $box->intersect($this->mediaBox);
        if ($clamped->isEmpty()) {
            throw new InvalidArgumentException('Page box lies outside the media box.');
        }

        return $clamped;
    }
}
