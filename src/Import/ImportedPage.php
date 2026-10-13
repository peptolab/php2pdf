<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Override;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Color\ColourPolicyInterface;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Objects;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Geometry\Matrix;

/**
 * A page of an imported PDF wrapped as reusable artwork, bounded by one of its page boxes and
 * honouring its display rotation. Written to the output on first placement. With an annotation
 * flattener, the annotations that print are drawn into it, and its links to web addresses are
 * there for a page it is placed on to make links of.
 *
 * @api
 */
final class ImportedPage implements PlaceableInterface
{
    private ?Reference $reference = null;
    private readonly Box $bounds;
    private readonly Matrix $matrix;
    private readonly Dimensions $size;

    /**
     * @internal
     *
     * @throws ParseException
     */
    public function __construct(
        private readonly Objects $objects,
        private readonly ColourPolicyInterface $colour,
        private readonly ObjectCopier $copier,
        public readonly PdfPage $page,
        public readonly PageBox $box,
        private readonly ?HiddenContent $hidden = null,
        private readonly ?AnnotationFlattener $annotations = null,
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

    /** @throws ParseException */
    #[Override]
    public function drawAt(Canvas $canvas, Matrix $matrix, Dimensions $size): void
    {
        $canvas->objects()->assertOwns($this->objects, 'An imported page');
        if (null === $this->reference && $this->colour->checksColour()) {
            foreach ($this->page->deviceColourSpaces() as $space) {
                $this->colour->assertImportedColour($space, $this->page->number);
            }
        }
        $this->reference ??= $this->write();
        $canvas->drawXObject($this->reference, $matrix);
    }

    /**
     * The page's links to web addresses, by their areas in its own space (as naturalSize()
     * measures it), within its box; none unless its annotations are flattened.
     *
     * @return list<array{0: Box, 1: string}>
     *
     * @throws ParseException
     *
     * @internal
     */
    public function links(): array
    {
        $out    = [];
        $bounds = new Box(0.0, 0.0, $this->size->width, $this->size->height);
        foreach ($this->annotations?->links() ?? [] as [$rect, $uri]) {
            $area = $this->matrix->applyToBox($rect)->intersect($bounds);
            if (! $area->isEmpty()) {
                $out[] = [$area, $uri];
            }
        }

        return $out;
    }

    #[Override]
    public function naturalSize(?float $dpi = null): Dimensions
    {
        return $this->size;
    }

    /** @throws ParseException */
    private function write(): Reference
    {
        $page    = $this->page;
        $content = $page->content();
        if (null !== $this->hidden) {
            $content = $this->hidden->filter($content, $page->resources());
        }
        [$resources, $content] = null === $this->annotations
            ? [$page->resources() ?? new Dictionary(), $content]
            : $this->annotations->flatten($page->resources(), $content);
        $dict = Dictionary::of([
            'Type'      => new Name('XObject'),
            'Subtype'   => new Name('Form'),
            'FormType'  => 1,
            'BBox'      => $this->bounds->toRect(),
            'Matrix'    => $this->matrix->toArray(),
            'Resources' => $this->copier->copyDictionary($resources),
        ]);
        $group = $page->group();
        if (null !== $group) {
            $dict->set('Group', $this->copier->copyDictionary($group));
        }

        return $this->objects->add(new Stream($dict, $content));
    }
}
