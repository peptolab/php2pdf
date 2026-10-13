<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Matrix;

use function abs;
use function count;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function min;

/**
 * A source page's annotations as artwork: the appearance of each that prints, fitted to its
 * rectangle as a viewer draws it (ISO 32000-2, 12.5.5), and its links to web addresses.
 *
 * @internal
 */
final readonly class AnnotationFlattener
{
    /** Annotation flags: hidden, and printed. */
    private const int HIDDEN = 2;

    private const int PRINT = 4;

    public function __construct(
        private PdfReader $reader,
        private PdfPage $page,
        private ?HiddenContent $hidden = null,
    ) {}

    /** A name not yet in a resource dictionary, from $name. */
    private static function unused(Dictionary $dictionary, string $name): string
    {
        $unused = $name;
        for ($n = 2; $dictionary->has($unused); ++$n) {
            $unused = "{$name}_{$n}";
        }

        return $unused;
    }

    /**
     * The appearances to draw, in the page's order: each appearance stream as the source refers
     * to it, the matrix that fits it to its annotation's rectangle, and the annotation's optional
     * content, if any.
     *
     * @return list<array{0: mixed, 1: Matrix, 2: mixed}>
     *
     * @throws ParseException
     */
    public function appearances(): array
    {
        $out = [];
        foreach ($this->annotations() as $annotation) {
            $flags = $this->reader->get($annotation, 'F');
            $flags = is_int($flags) ? $flags : 0;
            $oc    = $annotation->get('OC');
            if (0 === ($flags & self::PRINT) || 0 !== ($flags & self::HIDDEN) || $this->isHidden($oc)) {
                continue;
            }
            $appearance = $this->appearance($annotation);
            $stream     = $this->reader->resolve($appearance);
            $rect       = $this->box($this->reader->get($annotation, 'Rect'));
            if (! $stream instanceof Stream || null === $rect) {
                continue;
            }
            $matrix = $this->fit($stream, $rect);
            if (null !== $matrix) {
                $out[] = [$appearance, $matrix, $oc];
            }
        }

        return $out;
    }

    /**
     * The page's resources and content with its annotations' appearances drawn after the content,
     * each fitted to its rectangle and marked with its optional content; as they were when no
     * annotation prints. The resources still refer to the source's objects.
     *
     * @return array{0: Dictionary, 1: string}
     *
     * @throws ParseException
     */
    public function flatten(?Dictionary $resources, string $content): array
    {
        $resources   ??= new Dictionary();
        $appearances = $this->appearances();
        if ([] === $appearances) {
            return [$resources, $content];
        }
        $xobjects   = new Dictionary(($this->reader->dict($resources, 'XObject') ?? new Dictionary())->all());
        $properties = new Dictionary(($this->reader->dict($resources, 'Properties') ?? new Dictionary())->all());
        $draws      = '';
        foreach ($appearances as $i => [$appearance, $matrix, $oc]) {
            $name = self::unused($xobjects, "Annot{$i}");
            $xobjects->set($name, $appearance);
            $draw = "q {$matrix->toPdf()} cm /{$name} Do Q";
            if (null !== $oc) {
                $property = self::unused($properties, "AnnotOC{$i}");
                $properties->set($property, $oc);
                $draw = "/OC /{$property} BDC {$draw} EMC";
            }
            $draws .= "{$draw}\n";
        }
        $out = new Dictionary($resources->all());
        $out->set('XObject', $xobjects);
        $out->set('Properties', [] === $properties->all() ? null : $properties);

        return [$out, "q\n{$content}\nQ\n{$draws}"];
    }

    /**
     * The page's links to web addresses that are not hidden, by their rectangles in the source
     * page's space.
     *
     * @return list<array{0: Box, 1: string}>
     *
     * @throws ParseException
     */
    public function links(): array
    {
        $out = [];
        foreach ($this->annotations() as $annotation) {
            $subtype = $annotation->get('Subtype');
            $flags   = $this->reader->get($annotation, 'F');
            $action  = $this->reader->dict($annotation, 'A');
            $uri     = null === $action ? null : $this->reader->get($action, 'URI');
            $rect    = $this->box($this->reader->get($annotation, 'Rect'));
            if (
                ! $subtype instanceof Name
                || 'Link' !== $subtype->value
                || (
                    is_int($flags)
                    && 0
                    !== ($flags & self::HIDDEN)
                )
                || ! is_string($uri)
                || null === $rect
            ) {
                continue;
            }
            $out[] = [$rect, $uri];
        }

        return $out;
    }

    /**
     * @return list<Dictionary>
     *
     * @throws ParseException
     */
    private function annotations(): array
    {
        $annots = $this->reader->get($this->page->dictionary(), 'Annots');
        $out    = [];
        foreach (is_array($annots) ? $annots : [] as $annot) {
            $dict = $this->reader->resolve($annot);
            if ($dict instanceof Dictionary) {
                $out[] = $dict;
            }
        }

        return $out;
    }

    /**
     * The annotation's normal appearance, as the source refers to it: /AP /N, or the state of it
     * /AS names.
     *
     * @throws ParseException
     */
    private function appearance(Dictionary $annotation): mixed
    {
        $ap = $this->reader->dict($annotation, 'AP');
        if (null === $ap) {
            return null;
        }
        $normal = $ap->get('N');
        $states = $this->reader->resolve($normal);
        if (! $states instanceof Dictionary) {
            return $normal;
        }
        $state = $annotation->get('AS');

        return $state instanceof Name ? $states->get($state->value) : null;
    }

    /**
     * A rectangle as a box, its corners in either order; null when it is not four numbers.
     *
     * @throws ParseException
     */
    private function box(mixed $rect): ?Box
    {
        if (! is_array($rect)) {
            return null;
        }
        $n = [];
        foreach ($rect as $value) {
            $value = $this->reader->resolve($value);
            if (! is_numeric($value)) {
                return null;
            }
            $n[] = (float) $value;
        }
        if (4 !== count($n)) {
            return null;
        }
        [$x1, $y1, $x2, $y2] = [$n[0], $n[1] ?? 0.0, $n[2] ?? 0.0, $n[3] ?? 0.0];

        return new Box(min($x1, $x2), min($y1, $y2), abs($x2 - $x1), abs($y2 - $y1));
    }

    /**
     * The matrix that fits an appearance to its rectangle: its bounding box, transformed by its
     * own matrix, scaled and moved onto the rectangle. Null for an appearance with no area.
     *
     * @throws ParseException
     */
    private function fit(Stream $appearance, Box $rect): ?Matrix
    {
        $bbox = $this->box($this->reader->get($appearance->dictionary, 'BBox'));
        if (null === $bbox) {
            return null;
        }
        $m      = $this->reader->get($appearance->dictionary, 'Matrix');
        $values = [];
        foreach (is_array($m) ? $m : [] as $value) {
            $value    = $this->reader->resolve($value);
            $values[] = is_numeric($value) ? (float) $value : 0.0;
        }
        $own    = 6 === count($values) ? new Matrix(...$values) : Matrix::identity();
        $bounds = $own->applyToBox($bbox);
        if ($bounds->width <= 0.0 || $bounds->height <= 0.0) {
            return null;
        }

        return Matrix::translation(-$bounds->x, -$bounds->y)
            ->then(Matrix::scaling($rect->width / $bounds->width, $rect->height / $bounds->height))
            ->then(Matrix::translation($rect->x, $rect->y));
    }

    /**
     * Whether optional content hides the annotation, when hidden layers are removed.
     *
     * @throws ParseException
     */
    private function isHidden(mixed $oc): bool
    {
        return null !== $oc && null !== $this->hidden && $this->hidden->isHidden($oc);
    }
}
