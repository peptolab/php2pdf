<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Closure;
use Override;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Canvas\Tagger;
use Php2Pdf\Color\ColourPolicyInterface;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Objects;
use Php2Pdf\Exception\ConformanceException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Structure\PageMarks;
use Php2Pdf\Structure\StructureElement;
use Php2Pdf\Structure\StructureRole;

use function count;
use function implode;
use function is_int;
use function sprintf;

/**
 * A page of an imported PDF wrapped as reusable artwork, bounded by one of its page boxes and
 * honouring its display rotation. Written to the output on first placement. With an annotation
 * flattener, the annotations that print are drawn into it, and its links to web addresses are
 * there for a page it is placed on to make links of.
 *
 * In a tagged document, a page of a tagged file brings its structure where it is first placed
 * on a page, unless that is in an artifact or a figure: its elements are copied into a section
 * under the element being drawn, and a form of its own keeps the content's marks for them. Other
 * placements draw a form whose content claims no element.
 *
 * @api
 */
final class ImportedPage implements PlaceableInterface
{
    private ?Reference $reference = null;

    /** Whether the page's structure has been copied where it was placed. */
    private bool $claimed = false;

    /** The page's structure, once read: false before, null for none. */
    private StructureNode|false|null $structure = false;
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
        /** The standards that need every font embedded (PDF/X, PDF/A), or null. */
        private readonly ?string $embeddingRequiredBy = null,
        /** Reads the structure of the page's file in a tagged document; null in an untagged one. */
        private readonly ?StructureReader $structureReader = null,
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
        if (null === $this->reference && null !== $this->embeddingRequiredBy) {
            $this->assertEmbedded($this->embeddingRequiredBy);
        }
        $tagger    = $canvas->tagger();
        $structure = $this->structure();
        if (
            null !== $structure
            && null !== $tagger
            && ! $this->claimed
            && ! $tagger->isArtifact()
            && StructureRole::Figure->value !== $tagger->current()->type
        ) {
            $this->claimed = true;
            $form          = $this->objects->allocate();
            $marks         = $tagger->form($form);
            $section       = $tagger->element(StructureRole::Section);
            $section->attach();
            $this->copy($structure, $section, $tagger, $marks);
            [$dict, $content] = $this->form(
                null === $this->annotations ? null : $this->annotations->flattenAsArtifacts(...),
            );
            $dict->set('StructParents', $marks->key);
            $this->objects->write($form, new Stream($dict, $content));
            $canvas->drawMarkedXObject($form, $matrix);

            return;
        }
        $this->reference ??= $this->write($structure);
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

    /**
     * Refuses a page whose fonts are not all embedded.
     *
     * @throws ConformanceException when one is not
     * @throws ParseException
     */
    private function assertEmbedded(string $standards): void
    {
        $missing = $this->page->unembeddedFonts();
        if ([] !== $missing) {
            throw new ConformanceException(sprintf(
                '%s requires every font to be embedded, and imported page %d uses %s without embedding %s.',
                $standards,
                $this->page->number,
                implode(', ', $missing),
                1 === count($missing) ? 'it' : 'them',
            ));
        }
    }

    /**
     * Copies a node's kids under an element: the MCIDs as the form's marks for it, and the
     * elements, with the entries they bring, in their order.
     *
     * @throws ParseException
     */
    private function copy(StructureNode $node, StructureElement $parent, Tagger $tagger, PageMarks $marks): void
    {
        foreach ($node->kids as $kid) {
            if (is_int($kid)) {
                $marks->claim($parent, $kid);

                continue;
            }
            $entries = new Dictionary();
            foreach ($kid->entries->all() as $key => $value) {
                $entries->set($key, $this->copier->copy($value));
            }
            $element = $tagger->copied($kid->type, $parent, $entries);
            $element->attach();
            $this->copy($kid, $element, $tagger, $marks);
        }
    }

    /**
     * The form's dictionary and content: the page's content without its hidden layers, with its
     * annotations flattened by the given flattening.
     *
     * @param (Closure(?Dictionary, string): array{0: Dictionary, 1: string})|null $flatten
     *
     * @return array{0: Dictionary, 1: string}
     *
     * @throws ParseException
     */
    private function form(?Closure $flatten): array
    {
        $page    = $this->page;
        $content = $page->content();
        if (null !== $this->hidden) {
            $content = $this->hidden->filter($content, $page->resources());
        }
        [$resources, $content] = null === $flatten
            ? [$page->resources() ?? new Dictionary(), $content]
            : $flatten($page->resources(), $content);
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

        return [$dict, $content];
    }

    /**
     * The page's structure in a tagged document, read once; null in an untagged one, or for a
     * page without.
     *
     * @throws ParseException
     */
    private function structure(): ?StructureNode
    {
        if (false === $this->structure) {
            $this->structure = $this->structureReader?->read($this->page);
        }

        return $this->structure;
    }

    /**
     * Writes the form other placements draw: without MCIDs when the page has a structure, which
     * only the placement that copied it claims.
     *
     * @throws ParseException
     */
    private function write(?StructureNode $structure): Reference
    {
        [$dict, $content] = $this->form(null === $this->annotations ? null : $this->annotations->flatten(...));
        if (null !== $structure && null !== $this->structureReader) {
            $content = $this->structureReader->withoutMcids($content);
        }

        return $this->objects->add(new Stream($dict, $content));
    }
}
