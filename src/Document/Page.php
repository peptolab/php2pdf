<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Closure;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Canvas\Resources;
use Php2Pdf\Canvas\Tagger;
use Php2Pdf\Canvas\TextFragments;
use Php2Pdf\Canvas\TextlineOptions;
use Php2Pdf\Canvas\TextlinePlacement;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Exception\ConformanceException;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\StateException;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Fit\Placement;
use Php2Pdf\Flow\FlowableInterface;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Import\ImportedPage;
use Php2Pdf\Navigation\Destination;
use Php2Pdf\Structure\StructureElement;
use Php2Pdf\Structure\StructureRole;
use Php2Pdf\Table\Table;
use Php2Pdf\Text\FlowOptions;
use Php2Pdf\Text\Textflow;
use WeakReference;

use function implode;
use function is_string;
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

    /**
     * @var list<array{0: Box, 1: Destination|string, 2: string|null, 3: StructureElement|null}> link
     *      areas in PDF coordinates, targets, tooltips, and their elements on a tagged page
     */
    private array $links = [];

    /** @internal */
    public function __construct(
        private readonly Document $document,
        Dimensions $size,
        private readonly bool $topDown,
        private readonly int $number,
        ?Tagging $tagging = null,
    ) {
        $this->mediaBox  = new Box(0.0, 0.0, $size->width, $size->height);
        $this->resources = new Resources($document->colour()->defaultRgb());
        $page            = WeakReference::create($this);
        $this->canvas    = new Canvas(
            $document->objects(),
            $document->colour(),
            $this->resources,
            $size->height,
            $topDown,
            static fn(): TextFragments => $document->text()->fragments,
            links: static function (
                Box $area,
                Destination|string $target,
                ?string $tooltip,
                ?StructureElement $element,
            ) use ($page): void {
                $owner = $page->get();
                $owner?->link($area, $target, $tooltip, $element);
            },
            tagger: null === $tagging
                ? null
                : new Tagger($tagging->tree, $tagging->tree->page($document->objects()->allocate())),
        );
    }

    /**
     * Makes an area of the page a link to a URL (or any URI such as mailto:) or to a destination
     * in this document. The link draws nothing; style the content underneath as you like. Under
     * PDF/X a link must lie outside the bleed box (the trim box when no bleed box is set). A
     * tooltip is shown by viewers when the pointer rests on the link.
     *
     * @throws ConformanceException under PDF/X when the link overlaps a page box already set
     */
    public function addLink(Box $area, Destination|string $target, ?string $tooltip = null): self
    {
        $this->link($this->toPdfBox($area), $target, $tooltip, $this->canvas->element(StructureRole::Link));

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
     * Writes the content stream and page dictionary. Under PDF/X the page boxes and links are
     * checked first, so a page that fails is left unwritten and open. PDF/X needs a trim or art
     * box on every page; without either, the crop box (or media box) becomes the trim box.
     *
     * @internal
     */
    public function finish(Reference $parent): Reference
    {
        if ($this->finished) {
            throw new StateException(sprintf('Page %d has already been finished.', $this->number));
        }

        $pdfx = $this->document->options()->pdfx;
        if (null !== $pdfx) {
            $trim =
                null === $this->trimBox && null === $this->artBox
                    ? $this->cropBox ?? $this->mediaBox
                    : $this->trimBox;
            $this->assertPdfXBoxes($pdfx, $trim);
            $this->assertPdfXLinks($pdfx, $this->links, $trim);
            $this->trimBox = $trim;
        }
        $this->finished = true;

        $writer   = $this->document->objects();
        $contents = $writer->add(Stream::of($this->canvas->finish()));
        $tagger   = $this->canvas->tagger();
        $annots   = $this->annotations($tagger);
        $dict     = Dictionary::of([
            'Type'          => new Name('Page'),
            'Parent'        => $parent,
            'Annots'        => [] === $annots ? null : $annots,
            'MediaBox'      => $this->mediaBox->toRect(),
            'CropBox'       => $this->pdfRect($this->cropBox),
            'BleedBox'      => $this->pdfRect($this->bleedBox),
            'TrimBox'       => $this->pdfRect($this->trimBox),
            'ArtBox'        => $this->pdfRect($this->artBox),
            'Rotate'        => 0 === $this->rotation ? null : $this->rotation,
            'Resources'     => $this->resources->toDictionary(),
            'Contents'      => $contents,
            'StructParents' => $tagger?->marks()->key,
            'Tabs'          => null === $tagger ? null : new Name('S'),
            'Group'         => null === $pdfx
                ? null
                : Dictionary::of([
                    'S'  => new Name('Transparency'),
                    'CS' => $this->document->colour()->blendingColorSpace(),
                    'I'  => true,
                ]),
        ]);

        if (null === $tagger) {
            return $writer->add($dict);
        }
        $writer->write($tagger->marks()->page, $dict);

        return $tagger->marks()->page;
    }

    /**
     * Places as many of a table's remaining rows as fit into a box on this page.
     *
     * @throws IoException
     * @throws ParseException
     */
    public function fitTable(Table $table, Box $box): FlowResult
    {
        return $table->fitInto($this->canvas(), $box);
    }

    /** Places as much of a Textflow as fits into a box on this page. */
    public function fitTextflow(Textflow $flow, Box $box, ?FlowOptions $options = null): FlowResult
    {
        return $flow->fitInto($this->canvas(), $box, $options);
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
        return $content->fitInto($this->canvas(), $box);
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

    /**
     * Fits an image, template or imported page into a box on this page. An imported page whose
     * annotations are flattened brings its links to web addresses, where it was placed. In a
     * tagged document artwork with alternate text is a figure, read as the text; without, it is
     * part of what is being tagged, or an artifact, which assistive technology skips.
     *
     * @throws ParseException when an imported page cannot be read
     */
    public function place(PlaceableInterface $object, Box $box, ?Fit $fit = null, ?string $alt = null): Placement
    {
        $canvas    = $this->canvas();
        $placement = null;
        if (null !== $alt && $canvas->isTagged()) {
            $canvas->tag(
                StructureRole::Figure,
                static function (Canvas $canvas) use ($object, $box, $fit, &$placement): void {
                    $placement = $canvas->place($object, $box, $fit);
                },
                $alt,
            );
        }
        $placement ??= $canvas->place($object, $box, $fit);
        if ($object instanceof ImportedPage) {
            $this->importLinks($object, $placement);
        }

        return $placement;
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

    /** @throws ConformanceException under PDF/X when the box breaks the PDF/X box rules */
    public function setArtBox(?Box $box): self
    {
        $previous     = $this->artBox;
        $this->artBox = $this->clamp($box);
        $this->checkBoxes(static function (Page $page) use ($previous): void {
            $page->artBox = $previous;
        });

        return $this;
    }

    /** @throws ConformanceException under PDF/X when the box breaks the PDF/X box rules */
    public function setBleedBox(?Box $box): self
    {
        $previous       = $this->bleedBox;
        $this->bleedBox = $this->clamp($box);
        $this->checkBoxes(static function (Page $page) use ($previous): void {
            $page->bleedBox = $previous;
        });

        return $this;
    }

    /** @throws ConformanceException under PDF/X when the box breaks the PDF/X box rules */
    public function setCropBox(?Box $box): self
    {
        $previous      = $this->cropBox;
        $this->cropBox = $this->clamp($box);
        $this->checkBoxes(static function (Page $page) use ($previous): void {
            $page->cropBox = $previous;
        });

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

    /** @throws ConformanceException under PDF/X when the box breaks the PDF/X box rules */
    public function setTrimBox(?Box $box): self
    {
        $previous      = $this->trimBox;
        $this->trimBox = $this->clamp($box);
        $this->checkBoxes(static function (Page $page) use ($previous): void {
            $page->trimBox = $previous;
        });

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

    /**
     * Allocates the page's link annotations, each tagged as its element on a tagged page, with a
     * description of the link when it has no tooltip, as PDF/UA asks.
     *
     * @return list<Reference>
     */
    private function annotations(?Tagger $tagger): array
    {
        $annots = [];
        foreach ($this->links as [$area, $target, $tooltip, $element]) {
            $annots[] =
                null === $tagger || null === $element
                    ? $this->document->addLinkAnnotation($area, $target, $tooltip)
                    : $this->document->addLinkAnnotation(
                        $area,
                        $target,
                        $tooltip ?? (is_string($target) ? $target : 'A link to a place in this document'),
                        static fn(Reference $annotation): int => $tagger->annotation($element, $annotation),
                    );
        }

        return $annots;
    }

    private function assertOpen(): void
    {
        if ($this->finished) {
            throw new StateException(sprintf('Page %d has been written and can no longer be drawn on.', $this->number));
        }
    }

    /**
     * PDF/X boxes: a TrimBox or an ArtBox but not both, inside the BleedBox (which defaults to
     * the CropBox), inside the MediaBox.
     *
     * @throws ConformanceException when the boxes do not nest
     */
    private function assertPdfXBoxes(PdfX $pdfx, ?Box $trimBox): void
    {
        if (null !== $trimBox && null !== $this->artBox) {
            throw new ConformanceException(sprintf(
                '%s page %d has both a TrimBox and an ArtBox; set one.',
                $pdfx->value,
                $this->number,
            ));
        }
        // Every box is clipped to the media box as it is set, so the bleed box always lies within it.
        $bleed = $this->bleedBox ?? $this->cropBox ?? $this->mediaBox;
        foreach (['TrimBox' => $trimBox, 'ArtBox' => $this->artBox] as $name => $box) {
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

    /**
     * PDF/X allows annotations only outside the printed area. Link areas are in PDF space; the
     * boxes are in the page's coordinate mode.
     *
     * @param list<array{0: Box, 1: Destination|string, 2: string|null, 3: StructureElement|null}> $links
     *
     * @throws ConformanceException when a link overlaps the printed area
     */
    private function assertPdfXLinks(PdfX $pdfx, array $links, ?Box $trimBox): void
    {
        $printed = $this->toPdfBox($this->bleedBox ?? $trimBox ?? $this->artBox ?? $this->mediaBox);
        foreach ($links as [$area]) {
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

    /**
     * Under PDF/X, checks the boxes as they stand and any links against a printed box that has
     * been set, undoing the change that broke a rule before throwing. Links added before any
     * printed box is set are checked when the page is finished, against the default trim box.
     *
     * @param Closure(Page): void $undo
     *
     * @throws ConformanceException when the boxes or links break the PDF/X rules
     */
    private function checkBoxes(Closure $undo): void
    {
        $pdfx = $this->document->options()->pdfx;
        if (null === $pdfx) {
            return;
        }
        try {
            $this->assertPdfXBoxes($pdfx, $this->trimBox);
            if (null !== $this->bleedBox || null !== $this->trimBox || null !== $this->artBox) {
                $this->assertPdfXLinks($pdfx, $this->links, $this->trimBox);
            }
        } catch (ConformanceException $e) {
            $undo($this);

            throw $e;
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

    /**
     * Makes links of an imported page's links to web addresses, where it was placed and within
     * any clip of the placement. PDF/X keeps links out of the printed area, so under it they are
     * left out.
     *
     * @throws ParseException
     */
    private function importLinks(ImportedPage $page, Placement $placement): void
    {
        if (null !== $this->document->options()->pdfx) {
            return;
        }
        foreach ($page->links() as [$area, $uri]) {
            $on = $placement->matrix->applyToBox($area);
            $on = null === $placement->clip ? $on : $on->intersect($placement->clip);
            if (! $on->isEmpty()) {
                $this->addLink($on, $uri);
            }
        }
    }

    /**
     * Records a link area given in PDF space, checking it at once under PDF/X when a printed box
     * has been set.
     *
     * @throws ConformanceException when the link overlaps the printed area
     */
    private function link(
        Box $area,
        Destination|string $target,
        ?string $tooltip,
        ?StructureElement $element = null,
    ): void {
        $this->assertOpen();
        $link = [$area, $target, $tooltip, $element];
        $pdfx = $this->document->options()->pdfx;
        if (null !== $pdfx && (null !== $this->bleedBox || null !== $this->trimBox || null !== $this->artBox)) {
            $this->assertPdfXLinks($pdfx, [$link], $this->trimBox);
        }
        $this->links[] = $link;
    }

    /** @return list<float>|null */
    private function pdfRect(?Box $box): ?array
    {
        return null === $box ? null : $this->toPdfBox($box)->toRect();
    }
}
