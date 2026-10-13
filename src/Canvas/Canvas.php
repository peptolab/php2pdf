<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

use Closure;
use Php2Pdf\Color\AbstractGradient;
use Php2Pdf\Color\Cmyk;
use Php2Pdf\Color\ColorInterface;
use Php2Pdf\Color\ColourPolicyInterface;
use Php2Pdf\Color\Gray;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Core\Num;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Objects;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Fit\FitEngine;
use Php2Pdf\Fit\Placement;
use Php2Pdf\Font\Bidi\BidiRuns;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\TextOptions;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Geometry\Origin;
use Php2Pdf\Geometry\Path;
use Php2Pdf\Navigation\Destination;
use Php2Pdf\Structure\StructureElement;
use Php2Pdf\Structure\StructureRole;
use Php2Pdf\Template\Template;

use function max;
use function min;

/**
 * Builds a content stream: graphics state, transforms, paths, painting and colour. Shared by
 * pages and templates (form XObjects).
 *
 * Angles are in degrees. With a bottom-left origin positive angles rotate counter-clockwise; on a
 * top-down canvas they rotate clockwise, as in design tools.
 *
 * @api
 */
final class Canvas
{
    /**
     * @internal
     *
     * @param Closure(): TextFragments $fragments what the words of the document's text show,
     *                                           shared by its pages and templates, asked for when
     *                                           text is first drawn
     * @param bool $inheritsState whether the content is drawn inside other content, as a form
     *                            XObject is, so its text state starts unknown rather than at the
     *                            defaults every page starts with
     * @param (Closure(Box, Destination|string, ?string, ?StructureElement): void)|null $links where
     *                            a page's canvas sends link areas, in default user space, with the
     *                            elements they belong to on a tagged page; artwork cannot carry links
     * @param Tagger|null $tagger what a tagged page's content is drawn as
     */
    public function __construct(
        private readonly Objects $objects,
        ColourPolicyInterface $colour,
        private readonly Resources $resources,
        private readonly float $height,
        private readonly bool $topDown,
        Closure $fragments,
        bool $inheritsState = false,
        private readonly ?Closure $links = null,
        private readonly ?Tagger $tagger = null,
    ) {
        $this->stream = new ContentStream($height, $topDown, $inheritsState);
        if (null !== $tagger) {
            $this->stream->tagWith($tagger);
        }
        $this->paint = new Paint($objects, $resources, new ColourOperators($objects, $colour, $resources));
        $this->text  = new TextPainter($this->stream, $resources, $objects, $fragments);
        // A document that checks colour can change its output intent, and with it which device
        // colours are allowed, so its colours are checked each time rather than remembered.
        $this->remembersFill = ! $colour->checksColour();
    }

    /**
     * Appends a circular arc from $startDegrees to $endDegrees. It connects to the current point
     * with a line when the path is open, and starts a new subpath otherwise.
     */
    public function arc(float $cx, float $cy, float $radius, float $startDegrees, float $endDegrees): self
    {
        $arc = Path::arc($cx, $cy, $radius, $startDegrees, $endDegrees);

        return $this->path($this->stream->hasCurrentPoint() ? $arc->joined() : $arc);
    }

    /**
     * Shows the runs from here to endText() in one text object, each line moved from the one
     * before, as a box of laid-out text is drawn. Nothing but text may be drawn until endText().
     *
     * @internal
     */
    public function beginText(): self
    {
        $this->text->begin();

        return $this;
    }

    public function box(Box $box): self
    {
        return $this->rect($box->x, $box->y, $box->width, $box->height);
    }

    public function circle(float $cx, float $cy, float $radius): self
    {
        return $this->ellipse($cx, $cy, $radius, $radius);
    }

    /** Intersects the clip region with the current path and discards the path. */
    public function clip(FillRule $rule = FillRule::NonZero): self
    {
        $this->stream->paint(FillRule::EvenOdd === $rule ? 'W* n' : 'W n');

        return $this;
    }

    public function closeAndStroke(): self
    {
        $this->stream->paint('s');

        return $this;
    }

    public function closePath(): self
    {
        $this->stream->closePath();

        return $this;
    }

    public function concat(Matrix $matrix): self
    {
        $this->stream->concat($matrix);

        return $this;
    }

    /** The current transformation matrix, from user space to the canvas's default space. */
    public function ctm(): Matrix
    {
        return $this->stream->ctm();
    }

    public function curveTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): self
    {
        $this->stream->curveTo($x1, $y1, $x2, $y2, $x3, $y3);

        return $this;
    }

    public function disableOverprint(): self
    {
        return $this->setGraphicsState(['op' => false, 'OP' => false]);
    }

    /**
     * Draws a form as drawXObject() does, outside any marked-content sequence: a form whose own
     * content is marked as the elements of the page's structure.
     *
     * @internal
     */
    public function drawMarkedXObject(Reference $ref, Matrix $matrix): self
    {
        $this->stream->appendUnmarked("q {$matrix->toPdf()} cm /{$this->resources->xObject($ref)} Do Q");

        return $this;
    }

    /**
     * Shows a single line of text with its baseline origin at (x, y).
     */
    public function drawText(
        string $text,
        FontInterface $font,
        float $size,
        float $x,
        float $y,
        ?TextOptions $options = null,
    ): self {
        $options ??= TextOptions::default();
        $matrix  = new Matrix(1.0, 0.0, 0.0, $this->topDown ? -1.0 : 1.0, $x, $y);

        return $this->showRun(BidiRuns::shape($font, $text, $options), $font, $size, $matrix, $options);
    }

    /**
     * Draws a form or image XObject with the given matrix, inside its own save/restore: for
     * artwork of your own that writes its XObject through Document::objects(). An image XObject
     * fills the unit square, so the matrix scales it to its size.
     */
    public function drawXObject(Reference $ref, Matrix $matrix): self
    {
        $this->stream->append("q {$matrix->toPdf()} cm /{$this->resources->xObject($ref)} Do Q");

        return $this;
    }

    /**
     * A new element under the given parent or the element being drawn, on a tagged page; null
     * otherwise.
     *
     * @internal
     */
    public function element(
        StructureRole $role,
        ?string $alt = null,
        ?Dictionary $attributes = null,
        ?StructureElement $parent = null,
    ): ?StructureElement {
        return $this->tagger?->element($role, $alt, $attributes, $parent);
    }

    public function ellipse(float $cx, float $cy, float $rx, float $ry): self
    {
        return $this->path(Path::ellipse($cx, $cy, $rx, $ry));
    }

    /**
     * Turns overprint on for fills and strokes.
     *
     * @param int $mode 0 paints every component (knockout), 1 only non-zero components.
     */
    public function enableOverprint(int $mode = 1): self
    {
        return $this->setGraphicsState(['op' => true, 'OP' => true, 'OPM' => $mode]);
    }

    /** Ends the path without painting it. */
    public function endPath(): self
    {
        $this->stream->paint('n');

        return $this;
    }

    /**
     * Ends the text object beginText() asked for.
     *
     * @internal
     */
    public function endText(): self
    {
        $this->text->end();

        return $this;
    }

    public function fill(FillRule $rule = FillRule::NonZero): self
    {
        $this->stream->paint(FillRule::EvenOdd === $rule ? 'f*' : 'f');

        return $this;
    }

    public function fillAndStroke(FillRule $rule = FillRule::NonZero): self
    {
        $this->stream->paint(FillRule::EvenOdd === $rule ? 'B*' : 'B');

        return $this;
    }

    public function fillRect(float $x, float $y, float $width, float $height): self
    {
        return $this->rect($x, $y, $width, $height)->fill();
    }

    /**
     * Returns the content stream and seals the canvas.
     *
     * @internal
     */
    public function finish(): string
    {
        return $this->stream->finish();
    }

    /**
     * Fits a single line of text into a box: positioned, optionally rotated, scaled or shrunk to
     * fit, with underline and strikeout drawn from the font's metrics.
     */
    public function fitTextline(
        string $text,
        FontInterface $font,
        float $size,
        Box $box,
        ?TextlineOptions $options = null,
    ): TextlinePlacement {
        return $this->text->fitLine($this, $text, $font, $size, $box, $options ?? new TextlineOptions());
    }

    public function height(): float
    {
        return $this->height;
    }

    /** @internal */
    public function isTagged(): bool
    {
        return null !== $this->tagger;
    }

    public function isTopDown(): bool
    {
        return $this->topDown;
    }

    public function line(float $x1, float $y1, float $x2, float $y2): self
    {
        return $this->moveTo($x1, $y1)->lineTo($x2, $y2)->stroke();
    }

    public function lineTo(float $x, float $y): self
    {
        $this->stream->lineTo($x, $y);

        return $this;
    }

    /**
     * Makes an area in the current user space a link, on a page's canvas; artwork (templates)
     * cannot carry links, so there it does nothing. A transformed area becomes its bounding box.
     *
     * @internal used by Textflow for linked text
     */
    public function linkArea(
        Box $area,
        Destination|string $target,
        ?string $tooltip = null,
        ?StructureElement $element = null,
    ): self {
        if (null === $this->links) {
            return $this;
        }
        $xs = [];
        $ys = [];
        foreach ([
            [$area->x, $area->y],
            [$area->right(), $area->y],
            [$area->x, $area->far()],
            [$area->right(), $area->far()],
        ] as [$x, $y]) {
            [$xs[], $ys[]] = $this->stream->ctm()->apply($x, $y);
        }
        ($this->links)(
            new Box(min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys)),
            $target,
            $tooltip,
            $element ?? $this->tagger?->element(StructureRole::Link),
        );

        return $this;
    }

    /**
     * Draws what follows as the element, or as an artifact for null, in place of what within()
     * draws as, on a tagged page.
     *
     * @internal
     */
    public function mark(?StructureElement $element): self
    {
        if (null !== $this->tagger && ! $this->tagger->isDrawing($element)) {
            $this->stream->closeMarked();
            $this->tagger->switchTo($element);
        }

        return $this;
    }

    public function moveTo(float $x, float $y): self
    {
        $this->stream->moveTo($x, $y);

        return $this;
    }

    /**
     * The document objects this canvas draws with, which what is drawn on it must belong to.
     *
     * @internal
     */
    public function objects(): Objects
    {
        return $this->objects;
    }

    public function origin(): Origin
    {
        return $this->topDown ? Origin::TopLeft : Origin::BottomLeft;
    }

    /**
     * Paints a gradient over the current clip region. Combine with clip() to fill a shape.
     */
    public function paintGradient(AbstractGradient $gradient): self
    {
        $this->stream->append("/{$this->paint->shading($gradient)} sh");

        return $this;
    }

    /**
     * Fits an image, template or imported page into a box and draws it.
     */
    /** Appends a path to the current path. */
    public function path(Path $path): self
    {
        foreach ($path->commands as [$op, $a]) {
            match ($op) {
                'M' => $this->stream->moveTo($a[0] ?? 0.0, $a[1] ?? 0.0),
                'L' => $this->stream->lineTo($a[0] ?? 0.0, $a[1] ?? 0.0),
                'C' => $this->stream->curveTo(
                    $a[0] ?? 0.0,
                    $a[1] ?? 0.0,
                    $a[2] ?? 0.0,
                    $a[3] ?? 0.0,
                    $a[4] ?? 0.0,
                    $a[5] ?? 0.0,
                ),
                'Z' => $this->stream->closePath(),
            };
        }

        return $this;
    }

    public function place(PlaceableInterface $object, Box $box, ?Fit $fit = null): Placement
    {
        $fit       ??= new Fit();
        $size      = $object->naturalSize($fit->dpi);
        $placement = FitEngine::place($size->width, $size->height, $box, $fit, $this->origin());
        $this->save();
        if (null !== $placement->clip) {
            $this->box($placement->clip)->clip();
        }
        $object->drawAt($this, $placement->matrix, $size);
        $this->restore();

        return $placement;
    }

    /** Appends raw content-stream syntax. A newline is added. */
    public function raw(string $syntax): self
    {
        $this->stream->raw($syntax);

        return $this;
    }

    public function rect(float $x, float $y, float $width, float $height): self
    {
        $this->stream->rect($x, $y, $width, $height);

        return $this;
    }

    /** @internal */
    public function resources(): Resources
    {
        return $this->resources;
    }

    public function restore(): self
    {
        $this->stream->restore();

        return $this;
    }

    public function rotate(float $degrees): self
    {
        return $this->concat(Matrix::rotation($degrees));
    }

    public function roundedRect(float $x, float $y, float $width, float $height, float $radius): self
    {
        $r = min($radius, $width / 2.0, $height / 2.0);

        return $r > 0.0
            ? $this->path(Path::rect($x, $y, $width, $height, $r, $r))
            : $this->rect($x, $y, $width, $height);
    }

    public function save(): self
    {
        $this->stream->save();

        return $this;
    }

    private readonly ContentStream $stream;
    private readonly Paint $paint;
    private readonly TextPainter $text;
    private readonly bool $remembersFill;

    /** The last device colour setFillColor() was given, and its operator. */
    private ?ColorInterface $lastFill = null;

    private string $lastFillOp = '';

    private const array FILL_OPS   = ['g', 'rg', 'k', 'cs', 'scn'];
    private const array STROKE_OPS = ['G', 'RG', 'K', 'CS', 'SCN'];

    public function scale(float $sx, ?float $sy = null): self
    {
        return $this->concat(Matrix::scaling($sx, $sy));
    }

    public function setBlendMode(BlendMode $mode): self
    {
        return $this->setGraphicsState(['BM' => new Name($mode->value)]);
    }

    /** Sets both fill and stroke colour. */
    public function setColor(ColorInterface $color): self
    {
        return $this->setFillColor($color)->setStrokeColor($color);
    }

    /**
     * @param list<float> $pattern Alternating dash and gap lengths; an empty list draws solid lines.
     */
    public function setDash(array $pattern, float $phase = 0.0): self
    {
        $this->stream->append('[' . Num::list($pattern) . '] ' . Num::format($phase) . ' d');

        return $this;
    }

    public function setFillColor(ColorInterface $color): self
    {
        $operation = $this->fillOperation($color);
        if ($this->stream->changes('fill', $operation)) {
            $this->stream->append($operation);
        }

        return $this;
    }

    /**
     * Fills subsequent paths and text with a gradient. Gradient coordinates are in the user space
     * current when this is called, or in that space transformed by $matrix.
     */
    public function setFillGradient(AbstractGradient $gradient, ?Matrix $matrix = null): self
    {
        $this->stream->append(
            "/Pattern cs /{$this->paint->gradientPattern($gradient, $this->patternMatrix($matrix))} scn",
        );
        $this->stream->forget('fill');

        return $this;
    }

    /**
     * Fills with a tiling pattern: the template repeated $xStep and $yStep apart.
     *
     * @param Matrix|null $matrix maps pattern coordinates into the current user space
     */
    public function setFillPattern(Template $tile, float $xStep, float $yStep, ?Matrix $matrix = null): self
    {
        $this->stream->append(
            "/Pattern cs /{$this->paint->tilePattern($tile, $xStep, $yStep, $this->patternMatrix($matrix))} scn",
        );
        $this->stream->forget('fill');

        return $this;
    }

    public function setLineCap(LineCap $cap): self
    {
        $this->stream->append("{$cap->value} J");

        return $this;
    }

    public function setLineJoin(LineJoin $join): self
    {
        $this->stream->append("{$join->value} j");

        return $this;
    }

    public function setLineWidth(float $width): self
    {
        $this->stream->append(Num::format($width) . ' w');

        return $this;
    }

    public function setMiterLimit(float $limit): self
    {
        $this->stream->append(Num::format($limit) . ' M');

        return $this;
    }

    /** Constant alpha from 0.0 (transparent) to 1.0 (opaque). */
    public function setOpacity(float $fill, ?float $stroke = null): self
    {
        return $this->setGraphicsState(['ca' => $fill, 'CA' => $stroke ?? $fill]);
    }

    /**
     * Masks what is drawn next by a template's artwork, until restore(): by its luminosity, so
     * white shows what is under it and black hides it, or by its opacity. The template is placed
     * in the current user space, as if drawn with the identity matrix, and is made a
     * transparency group; outside it everything is hidden.
     */
    public function setSoftMask(Template $mask, SoftMaskType $type = SoftMaskType::Luminosity): self
    {
        $this->objects->assertOwns($mask->objects(), 'A template');
        $mask->asTransparencyGroup();

        return $this->setGraphicsState(['SMask' => Dictionary::of([
            'Type' => new Name('Mask'),
            'S'    => new Name(SoftMaskType::Luminosity === $type ? 'Luminosity' : 'Alpha'),
            'G'    => $mask->reference(),
        ])]);
    }

    public function setStrokeColor(ColorInterface $color): self
    {
        $this->stream->append($this->paint->colour($color, self::STROKE_OPS));

        return $this;
    }

    /** @param Matrix|null $matrix maps gradient coordinates into the current user space */
    public function setStrokeGradient(AbstractGradient $gradient, ?Matrix $matrix = null): self
    {
        $this->stream->append(
            "/Pattern CS /{$this->paint->gradientPattern($gradient, $this->patternMatrix($matrix))} SCN",
        );

        return $this;
    }

    /**
     * Strokes with a tiling pattern: the template repeated $xStep and $yStep apart.
     *
     * @param Matrix|null $matrix maps pattern coordinates into the current user space
     */
    public function setStrokePattern(Template $tile, float $xStep, float $yStep, ?Matrix $matrix = null): self
    {
        $this->stream->append(
            "/Pattern CS /{$this->paint->tilePattern($tile, $xStep, $yStep, $this->patternMatrix($matrix))} SCN",
        );

        return $this;
    }

    /**
     * Shows a shaped run with the given text matrix, recording the glyphs as used.
     *
     * @internal
     */
    public function showRun(
        GlyphRun $run,
        FontInterface $font,
        float $size,
        Matrix $textMatrix,
        TextOptions $options,
    ): self {
        $this->text->run($this, $run, $font, $size, $textMatrix, $options);

        return $this;
    }

    /**
     * As showRun(), for a run joined from $pieces, other runs, one after another: what is shown
     * for each piece is worked out once and reused wherever it is shown again. Every glyph of $run
     * is its piece's glyph, but for the last kernings $pieces gives.
     *
     * @internal
     */
    public function showRunPieces(
        GlyphRun $run,
        RunPieces $pieces,
        FontInterface $font,
        float $size,
        Matrix $textMatrix,
        TextOptions $options,
    ): self {
        $this->text->run($this, $run, $font, $size, $textMatrix, $options, $pieces);

        return $this;
    }

    public function skew(float $alphaDegrees, float $betaDegrees): self
    {
        return $this->concat(Matrix::skewing($alphaDegrees, $betaDegrees));
    }

    public function stroke(): self
    {
        $this->stream->paint('S');

        return $this;
    }

    public function strokeRect(float $x, float $y, float $width, float $height): self
    {
        return $this->rect($x, $y, $width, $height)->stroke();
    }

    /**
     * Draws content as an element of the document's logical structure, under the element being
     * drawn, if any: what assistive technology reads it as, with alternate text for artwork. On
     * a page of an untagged document, and in templates, the content is drawn as it is.
     *
     * @param Closure(Canvas): void $draw
     */
    public function tag(StructureRole $role, Closure $draw, ?string $alt = null): self
    {
        return $this->within($this->element($role, $alt), $draw);
    }

    /** @internal */
    public function tagger(): ?Tagger
    {
        return $this->tagger;
    }

    public function translate(float $tx, float $ty): self
    {
        return $this->concat(Matrix::translation($tx, $ty));
    }

    /**
     * Draws content as an element, or as an artifact for null, on a tagged page; otherwise as it
     * is.
     *
     * @internal
     *
     * @param Closure(Canvas): void $draw
     */
    public function within(?StructureElement $element, Closure $draw): self
    {
        $tagger = $this->tagger;
        if (null === $tagger) {
            $draw($this);

            return $this;
        }
        $this->stream->closeMarked();
        $tagger->enter($element);
        try {
            $draw($this);
        } finally {
            $this->stream->closeMarked();
            $tagger->leave();
        }

        return $this;
    }

    private function fillOperation(ColorInterface $color): string
    {
        if ($color === $this->lastFill) {
            return $this->lastFillOp;
        }
        $operation = $this->paint->colour($color, self::FILL_OPS);
        // Text sets the same colour object for each segment it draws. A device colour is
        // immutable and registers no resources, so its operator can be used again as it is.
        if ($this->remembersFill && ($color instanceof Gray || $color instanceof Rgb || $color instanceof Cmyk)) {
            $this->lastFill   = $color;
            $this->lastFillOp = $operation;
        }

        return $operation;
    }

    /** Maps pattern coordinates into the canvas's default space, through the current transform. */
    private function patternMatrix(?Matrix $matrix): Matrix
    {
        $ctm = $this->stream->ctm();

        return null === $matrix ? $ctm : $matrix->then($ctm);
    }

    /** @param array<string, mixed> $entries */
    private function setGraphicsState(array $entries): self
    {
        $this->stream->append("/{$this->paint->extGState($entries)} gs");

        return $this;
    }
}
