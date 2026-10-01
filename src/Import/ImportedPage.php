<?php

declare(strict_types=1);

namespace PhpPdf\Import;

use Override;
use PhpPdf\Canvas\Canvas;
use PhpPdf\Canvas\PlaceableInterface;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Document\Dimensions;
use PhpPdf\Document\Document;
use PhpPdf\Geometry\Box;
use PhpPdf\Geometry\Matrix;

/**
 * A page of an imported PDF wrapped as reusable artwork, bounded by one of its page boxes and
 * honouring its display rotation. Written to the output on first placement.
 *
 * @api
 */
final class ImportedPage implements PlaceableInterface
{
    private ?Reference $reference = null;
    private readonly Box $bounds;
    private readonly Matrix $matrix;
    private readonly Dimensions $size;

    /** @internal */
    public function __construct(
        private readonly Document $document,
        private readonly ObjectCopier $copier,
        public readonly PdfPage $page,
        public readonly PageBox $box,
    ) {
        $this->bounds = $page->box($box);
        $rotation     = Matrix::rotation(-$page->rotation());
        $rotated      = $rotation->applyToBox(new Box(0.0, 0.0, $this->bounds->width, $this->bounds->height));
        $this->matrix = Matrix::translation(-$this->bounds->x, -$this->bounds->y)
            ->then($rotation)
            ->then(Matrix::translation(-$rotated->x, -$rotated->y));
        $this->size = new Dimensions($rotated->width, $rotated->height);
    }

    /** The source page's box this template is bounded by, in the source page's coordinates. */
    public function boundingBox(): Box
    {
        return $this->bounds;
    }

    #[Override]
    public function drawAt(Canvas $canvas, Matrix $matrix, Dimensions $size): void
    {
        $canvas->document()->assertOwns($this->document, 'An imported page');
        if (null === $this->reference && null !== $this->document->options()->pdfx) {
            foreach ($this->page->deviceColourSpaces() as $space) {
                $this->document->assertImportedColour($space, $this->page->number);
            }
        }
        $this->reference ??= $this->write();
        $canvas->drawXObject($this->reference, $matrix);
    }

    #[Override]
    public function naturalSize(?float $dpi = null): Dimensions
    {
        return $this->size;
    }

    private function write(): Reference
    {
        $page = $this->page;
        $dict = Dictionary::of([
            'Type'      => new Name('XObject'),
            'Subtype'   => new Name('Form'),
            'FormType'  => 1,
            'BBox'      => $this->bounds->toRect(),
            'Matrix'    => $this->matrix->toArray(),
            'Resources' => $this->copier->copyDictionary($page->resources() ?? new Dictionary()),
        ]);
        $group = $page->group();
        if (null !== $group) {
            $dict->set('Group', $this->copier->copyDictionary($group));
        }

        return $this->document->writer()->add(new Stream($dict, $page->content()));
    }
}
