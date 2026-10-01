<?php

declare(strict_types=1);

namespace PhpPdf\Document;

use PhpPdf\Canvas\Canvas;
use PhpPdf\Canvas\PlaceableInterface;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Exception\ConformanceException;
use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Exception\PdfException;
use PhpPdf\Fit\Fit;
use PhpPdf\Fit\Placement;
use PhpPdf\Flow\FlowableInterface;
use PhpPdf\Flow\FlowResult;
use PhpPdf\Font\FontInterface;
use PhpPdf\Geometry\Box;
use PhpPdf\Table\Table;
use PhpPdf\Text\FlowOptions;
use PhpPdf\Text\Textflow;
use PhpPdf\Text\TextlineOptions;
use PhpPdf\Text\TextlinePlacement;

use function implode;
use function sprintf;

/**
 * A page being drawn. Coordinates are in points with the origin at the bottom-left corner, or at
 * the top-left corner with y growing downwards when the page is top-down. Page boxes are reported
 * and set in the same coordinate mode.
 *
 * @api
 */
final class Page
{
    /** Rounding slack, in points, when checking that page boxes nest. */
    private const float BOX_TOLERANCE = 0.01;

    private readonly Canvas $canvas;
    private readonly Resources $resources;
    private readonly Box $mediaBox;
    private ?Box $cropBox  = null;
    private ?Box $bleedBox = null;
    private ?Box $trimBox  = null;
    private ?Box $artBox   = null;
    private int  $rotation = 0;
    private bool $finished = false;

    /** @var list<array{0: Box, 1: Destination|string, 2: string|null}> link areas in PDF coordinates, targets and tooltips */
    private array $links = [];

    /** @internal */
    public function __construct(
        private readonly Document $document,
        Dimensions $size,
        private readonly bool $topDown,
        private readonly int $number,
    ) {
        $this->mediaBox  = new Box(0.0, 0.0, $size->width, $size->height);
        $this->resources = new Resources($document->defaultRgb());
        $this->canvas    = new Canvas($document, $this->resources, $size->height, $topDown);
    }

    /**
     * Makes an area of the page a link to a URL (or any URI such as mailto:) or to a destination
     * in this document. The link draws nothing; style the content underneath as you like. Under
     * PDF/X a link must lie outside the bleed box (the trim box when no bleed box is set). A
     * tooltip is shown by viewers when the pointer rests on the link.
     */
    public function addLink(Box $area, Destination|string $target, ?string $tooltip = null): self
    {
        $this->links[] = [$this->toPdfBox($area), $target, $tooltip];

        return $this;
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

        $pdfx = $this->document->options()->pdfx;
        if (null !== $pdfx && null === $this->trimBox && null === $this->artBox) {
            // PDF/X needs a trim or art box on every page: the crop box (or media box) is the trim.
            $this->trimBox = $this->cropBox ?? $this->mediaBox;
        }
        if (null !== $pdfx) {
            $this->assertPdfXBoxes($pdfx);
            $this->assertPdfXLinks($pdfx);
        }
        $annots = [];
        foreach ($this->links as [$area, $target, $tooltip]) {
            $annots[] = $this->document->addLinkAnnotation($area, $target, $tooltip);
        }
        $dict = Dictionary::of([
            'Type'      => new Name('Page'),
            'Parent'    => $parent,
            'Annots'    => [] === $annots ? null : $annots,
            'MediaBox'  => $this->mediaBox->toRect(),
            'CropBox'   => $this->pdfRect($this->cropBox),
            'BleedBox'  => $this->pdfRect($this->bleedBox),
            'TrimBox'   => $this->pdfRect($this->trimBox),
            'ArtBox'    => $this->pdfRect($this->artBox),
            'Rotate'    => 0 === $this->rotation ? null : $this->rotation,
            'Resources' => $this->resources->toDictionary(),
            'Contents'  => $contents,
            'Group'     => null === $pdfx
                ? null
                : Dictionary::of([
                    'S'  => new Name('Transparency'),
                    'CS' => $this->document->blendingColorSpace(),
                    'I'  => true,
                ]),
        ]);

        return $writer->add($dict);
    }

    /** Places as many of a table's remaining rows as fit into a box on this page. */
    public function fitTable(Table $table, Box $box): FlowResult
    {
        return $this->canvas()->fitTable($table, $box);
    }

    /** Places as much of a Textflow as fits into a box on this page. */
    public function fitTextflow(Textflow $flow, Box $box, ?FlowOptions $options = null): FlowResult
    {
        return $this->canvas()->fitTextflow($flow, $box, $options);
    }

    /** Fits a single line of text into a box on this page. */
    public function fitTextline(
        string $text,
        FontInterface $font,
        float $size,
        Box $box,
        ?TextlineOptions $options = null,
    ): TextlinePlacement {
        return $this->canvas()->fitTextline($text, $font, $size, $box, $options);
    }

    /** Places as much of any flowable content as fits into a box on this page. */
    public function flow(FlowableInterface $content, Box $box): FlowResult
    {
        return $this->canvas()->flow($content, $box);
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

    /** Fits an image, template or imported page into a box on this page. */
    public function place(PlaceableInterface $object, Box $box, ?Fit $fit = null): Placement
    {
        return $this->canvas()->place($object, $box, $fit);
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

    public function setArtBox(?Box $box): self
    {
        $this->artBox = $this->clamp($box);

        return $this;
    }

    public function setBleedBox(?Box $box): self
    {
        $this->bleedBox = $this->clamp($box);

        return $this;
    }

    public function setCropBox(?Box $box): self
    {
        $this->cropBox = $this->clamp($box);

        return $this;
    }

    /** Display rotation in degrees: 0, 90, 180 or 270 (clockwise). */
    public function setRotation(int $degrees): self
    {
        $degrees = (($degrees % 360) + 360) % 360;
        if (0 !== ($degrees % 90)) {
            throw new InvalidArgumentException('Page rotation must be a multiple of 90 degrees.');
        }
        $this->rotation = $degrees;

        return $this;
    }

    public function setTrimBox(?Box $box): self
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

    /**
     * PDF/X boxes: a TrimBox or an ArtBox but not both, inside the BleedBox (which defaults to
     * the CropBox), inside the MediaBox.
     *
     * @throws PdfException when the boxes do not nest
     */
    private function assertPdfXBoxes(PdfX $pdfx): void
    {
        if (null !== $this->trimBox && null !== $this->artBox) {
            throw new ConformanceException(sprintf(
                '%s page %d has both a TrimBox and an ArtBox; set one.',
                $pdfx->value,
                $this->number,
            ));
        }
        $bleed = $this->bleedBox ?? $this->cropBox ?? $this->mediaBox;
        if (! $this->mediaBox->encloses($bleed, self::BOX_TOLERANCE)) {
            throw new ConformanceException(sprintf(
                '%s page %d: the BleedBox must lie within the MediaBox.',
                $pdfx->value,
                $this->number,
            ));
        }
        foreach (['TrimBox' => $this->trimBox, 'ArtBox' => $this->artBox] as $name => $box) {
            if (null !== $box && ! $bleed->encloses($box, self::BOX_TOLERANCE)) {
                throw new ConformanceException(sprintf(
                    '%s page %d: the %s must lie within the BleedBox.',
                    $pdfx->value,
                    $this->number,
                    $name,
                ));
            }
        }
    }

    /** PDF/X allows annotations only outside the printed area. */
    private function assertPdfXLinks(PdfX $pdfx): void
    {
        $printed = $this->bleedBox ?? $this->trimBox ?? $this->artBox ?? $this->mediaBox;
        foreach ($this->links as [$area]) {
            if ($printed->intersect($area)->isEmpty()) {
                continue;
            }
            throw new ConformanceException(sprintf(
                '%s page %d: a link at [%s] overlaps the %s; PDF/X allows links only outside it.',
                $pdfx->value,
                $this->number,
                implode(' ', $area->toRect()),
                null === $this->bleedBox ? 'TrimBox' : 'BleedBox',
            ));
        }
    }

    private function clamp(?Box $box): ?Box
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

    /** @return list<float>|null */
    private function pdfRect(?Box $box): ?array
    {
        return null === $box ? null : $this->toPdfBox($box)->toRect();
    }
}
