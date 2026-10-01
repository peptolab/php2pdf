<?php

declare(strict_types=1);

namespace PhpPdf\Canvas;

use PhpPdf\Color\AbstractGradient;
use PhpPdf\Color\Cmyk;
use PhpPdf\Color\ColorInterface;
use PhpPdf\Color\Gray;
use PhpPdf\Color\IccColor;
use PhpPdf\Color\Rgb;
use PhpPdf\Color\Spot;
use PhpPdf\Core\Num;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Core\Serializer;
use PhpPdf\Document\Document;
use PhpPdf\Document\Resources;
use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Exception\PdfException;
use PhpPdf\Fit\Fit;
use PhpPdf\Fit\FitEngine;
use PhpPdf\Fit\FitMethod;
use PhpPdf\Fit\Placement;
use PhpPdf\Flow\FlowableInterface;
use PhpPdf\Flow\FlowResult;
use PhpPdf\Font\FontInterface;
use PhpPdf\Font\GlyphRun;
use PhpPdf\Font\PdfFontInterface;
use PhpPdf\Font\TextOptions;
use PhpPdf\Geometry\Box;
use PhpPdf\Geometry\Matrix;
use PhpPdf\Geometry\Origin;
use PhpPdf\Table\Table;
use PhpPdf\Text\FlowOptions;
use PhpPdf\Text\Textflow;
use PhpPdf\Text\TextlineOptions;
use PhpPdf\Text\TextlinePlacement;

use function abs;
use function array_pop;
use function ceil;
use function cos;
use function count;
use function deg2rad;
use function implode;
use function max;
use function min;
use function sin;
use function sprintf;
use function strtr;
use function tan;

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
     * @param bool $inheritsState whether the content is drawn inside other content, as a form
     *                            XObject is, so its text state starts unknown rather than at the
     *                            defaults every page starts with
     */
    public function __construct(
        private readonly Document $document,
        private readonly Resources $resources,
        private readonly float $height,
        private readonly bool $topDown,
        bool $inheritsState = false,
    ) {
        $this->text = $inheritsState ? self::UNKNOWN_STATE : self::PAGE_STATE;
        $this->ctm  = Matrix::identity();
        if ($topDown) {
            $this->ctm = new Matrix(1.0, 0.0, 0.0, -1.0, 0.0, $height);
            $this->ops = '1 0 0 -1 0 ' . Num::format($height) . " cm\n";
        }
    }

    /** Bytes as a PDF literal string; carriage returns are escaped so readers do not normalise them. */
    private static function literal(string $bytes): string
    {
        return '(' . strtr($bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r']) . ')';
    }

    /**
     * The text-showing operation for a run: one Tj string when nothing adjusts the glyph
     * positions, otherwise a TJ array of strings interleaved with kerning, tracking and word
     * spacing adjustments in thousandths of an em.
     */
    private static function showOperation(
        GlyphRun $run,
        PdfFontInterface $font,
        float $size,
        TextOptions $options,
    ): string {
        $wordAdjust = 0.0 === $options->wordSpacing || $size <= 0.0 ? 0.0 : ($options->wordSpacing / $size) * 1000.0;
        $tracking   = $options->tracking;
        $parts      = [];
        $ids        = [];
        foreach ($run->glyphs as $glyph) {
            $ids[]  = $glyph->id;
            $adjust = $glyph->kerning + $tracking;
            if (0.0 !== $wordAdjust && $glyph->isSpace()) {
                $adjust += $wordAdjust;
            }
            if (0.0 !== $adjust) {
                $parts[] = self::literal($font->encode($ids));
                $parts[] = Num::format(-$adjust, 2);
                $ids     = [];
            }
        }
        if ([] !== $ids) {
            $parts[] = self::literal($font->encode($ids));
        }
        if (1 === count($parts)) {
            return "{$parts[0]} Tj";
        }

        return '[' . implode(' ', $parts) . '] TJ';
    }

    /**
     * Appends a circular arc from $startDegrees to $endDegrees. It connects to the current point
     * with a line when the path is open, and starts a new subpath otherwise.
     */
    public function arc(float $cx, float $cy, float $radius, float $startDegrees, float $endDegrees): self
    {
        $sweep = $endDegrees - $startDegrees;
        if (abs($sweep) > 360.0) {
            $sweep = $sweep < 0.0 ? -360.0 : 360.0;
        }
        $segments = max(1, (int) ceil(abs($sweep) / 90.0));
        $step     = deg2rad($sweep / $segments);
        $angle    = deg2rad($startDegrees);

        $x                     = $cx + ($radius * cos($angle));
        $y                     = $cy + ($radius * sin($angle));
        $this->ops             .= Num::list([$x, $y]) . ($this->hasCurrentPoint ? " l\n" : " m\n");
        $this->hasCurrentPoint = true;

        $k = (4.0 / 3.0) * tan($step / 4.0);
        for ($i = 0; $i < $segments; ++$i) {
            $next = $angle + $step;
            $cos1 = cos($angle);
            $sin1 = sin($angle);
            $cos2 = cos($next);
            $sin2 = sin($next);
            $this->curveTo(
                $cx + ($radius * ($cos1 - ($k * $sin1))),
                $cy + ($radius * ($sin1 + ($k * $cos1))),
                $cx + ($radius * ($cos2 + ($k * $sin2))),
                $cy + ($radius * ($sin2 - ($k * $cos2))),
                $cx + ($radius * $cos2),
                $cy + ($radius * $sin2),
            );
            $angle = $next;
        }

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
        return $this->paint(FillRule::EvenOdd === $rule ? 'W* n' : 'W n');
    }

    public function closeAndStroke(): self
    {
        return $this->paint('s');
    }

    public function closePath(): self
    {
        $this->ops .= "h\n";

        return $this;
    }

    public function concat(Matrix $matrix): self
    {
        if ($matrix->isIdentity()) {
            return $this;
        }
        $this->ctm = $matrix->then($this->ctm);
        $this->ops .= "{$matrix->toPdf()} cm\n";

        return $this;
    }

    /** The current transformation matrix, from user space to the canvas's default space. */
    public function ctm(): Matrix
    {
        return $this->ctm;
    }

    public function curveTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): self
    {
        $this->ops             .= Num::list([$x1, $y1, $x2, $y2, $x3, $y3]) . " c\n";
        $this->hasCurrentPoint = true;

        return $this;
    }

    public function disableOverprint(): self
    {
        return $this->extGState(['op' => false, 'OP' => false]);
    }

    /** @internal */
    public function document(): Document
    {
        return $this->document;
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

        return $this->showRun($font->shape($text, $options), $font, $size, $matrix, $options);
    }

    /**
     * Draws a form or image XObject with the given matrix, inside its own save/restore.
     *
     * @internal
     */
    public function drawXObject(Reference $ref, Matrix $matrix): self
    {
        $name      = $this->resources->xObject($ref);
        $this->ops .= "q {$matrix->toPdf()} cm /{$name} Do Q\n";

        return $this;
    }

    public function ellipse(float $cx, float $cy, float $rx, float $ry): self
    {
        $kx = self::KAPPA * $rx;
        $ky = self::KAPPA * $ry;

        return $this->moveTo($cx + $rx, $cy)
            ->curveTo($cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry)
            ->curveTo($cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy)
            ->curveTo($cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry)
            ->curveTo($cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy)
            ->closePath();
    }

    /**
     * Turns overprint on for fills and strokes.
     *
     * @param int $mode 0 paints every component (knockout), 1 only non-zero components.
     */
    public function enableOverprint(int $mode = 1): self
    {
        return $this->extGState(['op' => true, 'OP' => true, 'OPM' => $mode]);
    }

    /** Ends the path without painting it. */
    public function endPath(): self
    {
        return $this->paint('n');
    }

    public function fill(FillRule $rule = FillRule::NonZero): self
    {
        return $this->paint(FillRule::EvenOdd === $rule ? 'f*' : 'f');
    }

    public function fillAndStroke(FillRule $rule = FillRule::NonZero): self
    {
        return $this->paint(FillRule::EvenOdd === $rule ? 'B*' : 'B');
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
        if ($this->finished) {
            throw new PdfException('Canvas has already been finished.');
        }
        if ([] !== $this->ctmStack) {
            throw new PdfException(sprintf('Unbalanced save(): %d state(s) not restored.', count($this->ctmStack)));
        }
        $this->finished = true;

        return $this->ops;
    }

    /** Places as many of a table's remaining rows as fit into a box. */
    public function fitTable(Table $table, Box $box): FlowResult
    {
        return $table->fitInto($this, $box);
    }

    /**
     * Places as much of a Textflow as fits into the box and reports what happened.
     */
    public function fitTextflow(Textflow $flow, Box $box, ?FlowOptions $options = null): FlowResult
    {
        return $flow->fitInto($this, $box, $options);
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
        $options ??= new TextlineOptions();
        $run     = $font->shape($text, $options->text);
        $width   = max(0.01, $font->width($run, $size, $options->text));
        $ascent  = $font->ascender($size);
        $descent = $font->descender($size);
        $height  = max(0.01, $ascent - $descent);

        $fit = new Fit($options->method, $options->position, $options->rotate);
        if (FitMethod::Auto === $options->method) {
            $rotated = Matrix::rotation($options->rotate)->applyToBox(new Box(0.0, 0.0, $width, $height));
            $scale   = min(1.0, $box->width / $rotated->width, $box->height / $rotated->height);
            $fit     = new Fit(
                FitMethod::NoFit,
                $options->position,
                $options->rotate,
                max($scale, $options->shrinkLimit),
            );
        }
        $placement = FitEngine::place($width, $height, $box, $fit, $this->origin());
        $matrix    = $placement->matrix;

        $this->save();
        if (null !== $placement->clip) {
            $this->box($placement->clip)->clip();
        }
        // Object space: the line's box spans 0..width by 0..height with the baseline at -descent.
        $this->showRun($run, $font, $size, Matrix::translation(0.0, -$descent)->then($matrix), $options->text);
        if ($options->underline) {
            $this->decorationRect(
                $matrix,
                $width,
                -$descent + $font->underlinePosition($size),
                $font->underlineThickness($size),
            );
        }
        if ($options->strikeout) {
            $this->decorationRect(
                $matrix,
                $width,
                -$descent + $font->strikeoutPosition($size),
                $font->strikeoutSize($size),
            );
        }
        $this->restore();

        [$baselineX, $baselineY] = $matrix->apply(0.0, -$descent);

        return new TextlinePlacement(
            $placement->bounds,
            $baselineX,
            $baselineY,
            $size * $placement->scaleX,
            $width * $placement->scaleX,
            ! $placement->overflows($box),
        );
    }

    /** Places as much of any flowable content (a Textflow, a Table) as fits into a box. */
    public function flow(FlowableInterface $content, Box $box): FlowResult
    {
        return $content->fitInto($this, $box);
    }

    public function height(): float
    {
        return $this->height;
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
        $this->ops             .= Num::list([$x, $y]) . " l\n";
        $this->hasCurrentPoint = true;

        return $this;
    }

    public function moveTo(float $x, float $y): self
    {
        $this->ops             .= Num::list([$x, $y]) . " m\n";
        $this->hasCurrentPoint = true;

        return $this;
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
        $name      = $this->resources->shading($this->shadingRef($gradient));
        $this->ops .= "/{$name} sh\n";

        return $this;
    }

    /**
     * Fits an image, template or imported page into a box and draws it.
     */
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
        $this->ops  .= "{$syntax}\n";
        $this->text = self::UNKNOWN_STATE;

        return $this;
    }

    public function rect(float $x, float $y, float $width, float $height): self
    {
        $this->ops             .= Num::list([$x, $y, $width, $height]) . " re\n";
        $this->hasCurrentPoint = true;

        return $this;
    }

    /** @internal */
    public function resources(): Resources
    {
        return $this->resources;
    }

    public function restore(): self
    {
        if ([] === $this->ctmStack) {
            throw new PdfException('restore() without a matching save().');
        }
        $this->ctm             = array_pop($this->ctmStack);
        $this->text            = array_pop($this->textStack) ?? self::UNKNOWN_STATE;
        $this->ops             .= "Q\n";
        $this->hasCurrentPoint = false;

        return $this;
    }

    public function rotate(float $degrees): self
    {
        return $this->concat(Matrix::rotation($degrees));
    }

    public function roundedRect(float $x, float $y, float $width, float $height, float $radius): self
    {
        $r = min($radius, $width / 2.0, $height / 2.0);
        if ($r <= 0.0) {
            return $this->rect($x, $y, $width, $height);
        }
        $k  = self::KAPPA * $r;
        $x2 = $x + $width;
        $y2 = $y + $height;

        return $this->moveTo($x + $r, $y)
            ->lineTo($x2 - $r, $y)
            ->curveTo($x2 - $r + $k, $y, $x2, $y + $r - $k, $x2, $y + $r)
            ->lineTo($x2, $y2 - $r)
            ->curveTo($x2, $y2 - $r + $k, $x2 - $r + $k, $y2, $x2 - $r, $y2)
            ->lineTo($x + $r, $y2)
            ->curveTo($x + $r - $k, $y2, $x, $y2 - $r + $k, $x, $y2 - $r)
            ->lineTo($x, $y + $r)
            ->curveTo($x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y)
            ->closePath();
    }

    public function save(): self
    {
        $this->ctmStack[]  = $this->ctm;
        $this->textStack[] = $this->text;
        $this->ops         .= "q\n";

        return $this;
    }

    public function scale(float $sx, ?float $sy = null): self
    {
        return $this->concat(Matrix::scaling($sx, $sy));
    }

    public function setBlendMode(BlendMode $mode): self
    {
        return $this->extGState(['BM' => new Name($mode->value)]);
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
        $this->ops .= '[' . Num::list($pattern) . '] ' . Num::format($phase) . " d\n";

        return $this;
    }

    public function setFillColor(ColorInterface $color): self
    {
        $operation = $this->colorOps($color, self::FILL_OPS);
        if ($operation !== $this->text['fill']) {
            $this->ops          .= "{$operation}\n";
            $this->text['fill'] = $operation;
        }

        return $this;
    }

    /**
     * Fills subsequent paths and text with a gradient. Gradient coordinates are in the user space
     * current when this is called, or in that space transformed by $matrix.
     */
    public function setFillGradient(AbstractGradient $gradient, ?Matrix $matrix = null): self
    {
        $this->ops          .= "/Pattern cs /{$this->patternName($gradient, $matrix)} scn\n";
        $this->text['fill'] = null;

        return $this;
    }

    public function setLineCap(LineCap $cap): self
    {
        $this->ops .= "{$cap->value} J\n";

        return $this;
    }

    /** Bézier control-point factor for a quarter circle. */
    private const float KAPPA = 0.552_284_749_830_793_6;

    private const array INITIAL_STATE = [
        'Tf'   => null,
        'Tc'   => '0 Tc',
        'Tz'   => '100 Tz',
        'Ts'   => '0 Ts',
        'Tr'   => '0 Tr',
        'fill' => null,
    ];
    private const array PAGE_STATE    = [
        'Tf'   => null,
        'Tc'   => '0 Tc',
        'Tz'   => '100 Tz',
        'Ts'   => '0 Ts',
        'Tr'   => '0 Tr',
        'fill' => '0 g',
    ];
    private const array UNKNOWN_STATE = [
        'Tf'   => null,
        'Tc'   => null,
        'Tz'   => null,
        'Ts'   => null,
        'Tr'   => null,
        'fill' => null,
    ];

    private const array FILL_OPS   = ['g', 'rg', 'k', 'cs', 'scn'];
    private const array STROKE_OPS = ['G', 'RG', 'K', 'CS', 'SCN'];

    private string $ops = '';
    private Matrix $ctm;

    /** @var list<Matrix> */
    private array $ctmStack = [];

    /**
     * The text state and fill colour last written, as the operations that set them, so they are
     * written again only when they change. Null is unknown.
     *
     * @var array{Tf: string|null, Tc: string|null, Tz: string|null, Ts: string|null, Tr: string|null, fill: string|null}
     */
    private array $text;

    /** @var list<array{Tf: string|null, Tc: string|null, Tz: string|null, Ts: string|null, Tr: string|null, fill: string|null}> */
    private array $textStack = [];

    private bool $hasCurrentPoint = false;
    private bool $finished        = false;

    /** The device colour space a colour is drawn in, or ICCBased when it carries its own profile. */
    private static function deviceSpace(ColorInterface $color): string
    {
        return match (true) {
            $color instanceof Gray => 'DeviceGray',
            $color instanceof Rgb => 'DeviceRGB',
            $color instanceof Cmyk => 'DeviceCMYK',
            default => 'ICCBased',
        };
    }

    public function setLineJoin(LineJoin $join): self
    {
        $this->ops .= "{$join->value} j\n";

        return $this;
    }

    public function setLineWidth(float $width): self
    {
        $this->ops .= Num::format($width) . " w\n";

        return $this;
    }

    public function setMiterLimit(float $limit): self
    {
        $this->ops .= Num::format($limit) . " M\n";

        return $this;
    }

    /** Constant alpha from 0.0 (transparent) to 1.0 (opaque). */
    public function setOpacity(float $fill, ?float $stroke = null): self
    {
        return $this->extGState(['ca' => $fill, 'CA' => $stroke ?? $fill]);
    }

    public function setStrokeColor(ColorInterface $color): self
    {
        $this->ops .= "{$this->colorOps($color, self::STROKE_OPS)}\n";

        return $this;
    }

    /** @param Matrix|null $matrix maps gradient coordinates into the current user space */
    public function setStrokeGradient(AbstractGradient $gradient, ?Matrix $matrix = null): self
    {
        $this->ops .= "/Pattern CS /{$this->patternName($gradient, $matrix)} SCN\n";

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
        if ($run->isEmpty()) {
            return $this;
        }
        $font = $font instanceof PdfFontInterface
            ? $font
            : throw new InvalidArgumentException(sprintf(
                'Text can only be drawn with fonts from the document\'s FontRegistry, not %s.',
                $font::class,
            ));
        $this->document->assertOwns($font->document(), 'A font');
        $font->markUsed($run);
        $ops = 'BT';
        $tf  = "/{$this->resources->font($font->reference())} " . Num::format($size) . ' Tf';
        if ($tf !== $this->text['Tf']) {
            $ops              .= " {$tf}";
            $this->text['Tf'] = $tf;
        }
        $state = [
            'Tc' => Num::format($options->charSpacing) . ' Tc',
            'Tz' => Num::format($options->horizontalScale) . ' Tz',
            'Ts' => Num::format($options->rise) . ' Ts',
            'Tr' => "{$options->renderMode->value} Tr",
        ];
        foreach ($state as $operator => $operation) {
            if ($operation === ($this->text[$operator] ?? null)) {
                continue;
            }
            $ops                   .= " {$operation}";
            $this->text[$operator] = $operation;
        }
        $m        = $textMatrix;
        $position = 1.0 === $m->a && 0.0 === $m->b && 0.0 === $m->c && 1.0 === $m->d
            ? Num::format($m->e, 6) . ' ' . Num::format($m->f, 6) . ' Td'
            : "{$m->toPdf()} Tm";
        $this->ops .= "{$ops} {$position} " . self::showOperation($run, $font, $size, $options) . " ET\n";

        return $this;
    }

    public function skew(float $alphaDegrees, float $betaDegrees): self
    {
        return $this->concat(Matrix::skewing($alphaDegrees, $betaDegrees));
    }

    public function stroke(): self
    {
        return $this->paint('S');
    }

    public function strokeRect(float $x, float $y, float $width, float $height): self
    {
        return $this->rect($x, $y, $width, $height)->stroke();
    }

    public function translate(float $tx, float $ty): self
    {
        return $this->concat(Matrix::translation($tx, $ty));
    }

    /** @param array{string, string, string, string, string} $ops gray, rgb, cmyk, colour-space and component operators */
    private function colorOps(ColorInterface $color, array $ops): string
    {
        $components = Num::list($color->components());
        $this->document->assertOutputIntent();
        $this->document->assertDeviceColour(self::deviceSpace($color instanceof Spot ? $color->alternate : $color));

        return match (true) {
            $color instanceof Gray => "{$components} {$ops[0]}",
            $color instanceof Rgb => "{$components} {$ops[1]}",
            $color instanceof Cmyk => "{$components} {$ops[2]}",
            $color instanceof Spot => $this->colorSpaceOps($this->spotColorSpace($color), $components, $ops),
            $color instanceof IccColor => $this->colorSpaceOps($this->iccColorSpace($color), $components, $ops),
            default => throw new InvalidArgumentException(sprintf(
                'Colours must be Gray, Rgb, Cmyk, Spot or IccColor; %s is not one of them.',
                $color::class,
            )),
        };
    }

    /** @param array{string, string, string, string, string} $ops */
    private function colorSpaceOps(Reference $colorSpace, string $components, array $ops): string
    {
        $name = $this->resources->colorSpace($colorSpace);

        return "/{$name} {$ops[3]} {$components} {$ops[4]}";
    }

    /** A filled bar in the object space of a fitted text line (underline, strikeout). */
    private function decorationRect(Matrix $matrix, float $width, float $y, float $thickness): void
    {
        $this->ops .=
            "q {$matrix->toPdf()} cm 0 "
            . Num::format($y - ($thickness / 2.0))
            . ' '
            . Num::format($width)
            . ' '
            . Num::format($thickness)
            . " re f Q\n";
    }

    /** @param array<string, mixed> $entries */
    private function extGState(array $entries): self
    {
        $dict = Dictionary::of(['Type' => new Name('ExtGState'), ...$entries]);
        $ref  = $this->document->shared(
            'gs:' . Serializer::serialize($dict),
            static fn(): Dictionary => $dict,
        );
        $this->ops .= "/{$this->resources->extGState($ref)} gs\n";

        return $this;
    }

    private function iccColorSpace(IccColor $color): Reference
    {
        $profile = $color->profile;
        $stream  = $this->document->shared(
            "iccstream:{$profile->key()}",
            static fn(): Stream => new Stream(Dictionary::of(['N' => $profile->componentCount]), $profile->data),
        );

        return $this->document->shared(
            "icc:{$profile->key()}",
            /** @return array{Name, Reference} */
            static fn(): array => [new Name('ICCBased'), $stream],
        );
    }

    private function paint(string $operator): self
    {
        $this->ops             .= "{$operator}\n";
        $this->hasCurrentPoint = false;

        return $this;
    }

    private function patternName(AbstractGradient $gradient, ?Matrix $matrix): string
    {
        $shading = $this->shadingRef($gradient);
        $matrix  = null === $matrix ? $this->ctm : $matrix->then($this->ctm);
        $pattern = $this->document->shared(
            "pat:{$gradient->key()}:{$matrix->toPdf()}",
            static fn(): Dictionary => Dictionary::of([
                'PatternType' => 2,
                'Shading'     => $shading,
                'Matrix'      => $matrix->toArray(),
            ]),
        );

        return $this->resources->pattern($pattern);
    }

    private function shadingRef(AbstractGradient $gradient): Reference
    {
        $this->document->assertOutputIntent();
        $this->document->assertDeviceColour($gradient->colorSpaceName()->value, 'gradients');
        return $this->document->shared("sh:{$gradient->key()}", $gradient->toShading(...));
    }

    private function spotColorSpace(Spot $spot): Reference
    {
        $alternate = $spot->alternate;
        $key       = 'sep:' . $spot->name . ':' . $alternate::class . ':' . Num::list($alternate->components());

        return $this->document->shared($key, static function () use ($spot, $alternate): array {
            $white = match (true) {
                $alternate instanceof Gray => [1.0],
                $alternate instanceof Rgb => [1.0, 1.0, 1.0],
                default => [0.0, 0.0, 0.0, 0.0],
            };
            $space = match (true) {
                $alternate instanceof Gray => 'DeviceGray',
                $alternate instanceof Rgb => 'DeviceRGB',
                default => 'DeviceCMYK',
            };

            return [
                new Name('Separation'),
                new Name($spot->name),
                new Name($space),
                Dictionary::of([
                    'FunctionType' => 2,
                    'Domain'       => [0, 1],
                    'C0'           => $white,
                    'C1'           => $alternate->components(),
                    'N'            => 1,
                ]),
            ];
        });
    }
}
