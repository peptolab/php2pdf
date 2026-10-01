<?php

declare(strict_types=1);

namespace PhpPdf\Canvas;

use PhpPdf\Color\Cmyk;
use PhpPdf\Color\Color;
use PhpPdf\Color\Gradient;
use PhpPdf\Color\Gray;
use PhpPdf\Color\IccColor;
use PhpPdf\Color\Rgb;
use PhpPdf\Color\Spot;
use PhpPdf\Core\Num;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Document\Document;
use PhpPdf\Document\Resources;
use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Exception\PdfException;
use PhpPdf\Geometry\Box;
use PhpPdf\Geometry\Matrix;

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
    /** Bézier control-point factor for a quarter circle. */
    private const float KAPPA = 0.5522847498307936;

    private string $ops = '';
    private Matrix $ctm;

    /** @var list<Matrix> */
    private array $ctmStack = [];

    private bool $hasCurrentPoint = false;
    private bool $finished = false;

    /** @internal */
    public function __construct(
        private readonly Document $document,
        private readonly Resources $resources,
        private readonly float $height,
        private readonly bool $topDown,
    ) {
        $this->ctm = Matrix::identity();
        if ($topDown) {
            $this->ctm = new Matrix(1.0, 0.0, 0.0, -1.0, 0.0, $height);
            $this->ops = '1 0 0 -1 0 ' . Num::format($height) . " cm\n";
        }
    }

    // ----- Graphics state -------------------------------------------------------------------

    public function save(): self
    {
        $this->ctmStack[] = $this->ctm;
        $this->ops .= "q\n";

        return $this;
    }

    public function restore(): self
    {
        if ([] === $this->ctmStack) {
            throw new PdfException('restore() without a matching save().');
        }
        $this->ctm = array_pop($this->ctmStack);
        $this->ops .= "Q\n";
        $this->hasCurrentPoint = false;

        return $this;
    }

    /** The current transformation matrix, from user space to the canvas's default space. */
    public function ctm(): Matrix
    {
        return $this->ctm;
    }

    public function isTopDown(): bool
    {
        return $this->topDown;
    }

    public function height(): float
    {
        return $this->height;
    }

    // ----- Transforms -----------------------------------------------------------------------

    public function concat(Matrix $matrix): self
    {
        if ($matrix->isIdentity()) {
            return $this;
        }
        $this->ctm = $matrix->then($this->ctm);
        $this->ops .= $matrix->toPdf() . " cm\n";

        return $this;
    }

    public function translate(float $tx, float $ty): self
    {
        return $this->concat(Matrix::translation($tx, $ty));
    }

    public function rotate(float $degrees): self
    {
        return $this->concat(Matrix::rotation($degrees));
    }

    public function scale(float $sx, float|null $sy = null): self
    {
        return $this->concat(Matrix::scaling($sx, $sy));
    }

    public function skew(float $alphaDegrees, float $betaDegrees): self
    {
        return $this->concat(Matrix::skewing($alphaDegrees, $betaDegrees));
    }

    // ----- Line state -----------------------------------------------------------------------

    public function setLineWidth(float $width): self
    {
        $this->ops .= Num::format($width) . " w\n";

        return $this;
    }

    public function setLineCap(LineCap $cap): self
    {
        $this->ops .= $cap->value . " J\n";

        return $this;
    }

    public function setLineJoin(LineJoin $join): self
    {
        $this->ops .= $join->value . " j\n";

        return $this;
    }

    public function setMiterLimit(float $limit): self
    {
        $this->ops .= Num::format($limit) . " M\n";

        return $this;
    }

    /**
     * @param list<float> $pattern Alternating dash and gap lengths; an empty list draws solid lines.
     */
    public function setDash(array $pattern, float $phase = 0.0): self
    {
        $this->ops .= '[' . Num::list($pattern) . '] ' . Num::format($phase) . " d\n";

        return $this;
    }

    // ----- Colour ---------------------------------------------------------------------------

    public function setFillColor(Color $color): self
    {
        $this->ops .= $this->colorOps($color, true) . "\n";

        return $this;
    }

    public function setStrokeColor(Color $color): self
    {
        $this->ops .= $this->colorOps($color, false) . "\n";

        return $this;
    }

    /** Sets both fill and stroke colour. */
    public function setColor(Color $color): self
    {
        return $this->setFillColor($color)->setStrokeColor($color);
    }

    /**
     * Fills subsequent paths and text with a gradient. Gradient coordinates are in the user space
     * current when this is called.
     */
    public function setFillGradient(Gradient $gradient): self
    {
        $this->ops .= '/Pattern cs /' . $this->patternName($gradient) . " scn\n";

        return $this;
    }

    public function setStrokeGradient(Gradient $gradient): self
    {
        $this->ops .= '/Pattern CS /' . $this->patternName($gradient) . " SCN\n";

        return $this;
    }

    /**
     * Paints a gradient over the current clip region. Combine with clip() to fill a shape.
     */
    public function paintGradient(Gradient $gradient): self
    {
        $name = $this->resources->shading($this->shadingRef($gradient));
        $this->ops .= '/' . $name . " sh\n";

        return $this;
    }

    /** Constant alpha from 0.0 (transparent) to 1.0 (opaque). */
    public function setOpacity(float $fill, float|null $stroke = null): self
    {
        return $this->extGState(['ca' => $fill, 'CA' => $stroke ?? $fill]);
    }

    public function setBlendMode(BlendMode $mode): self
    {
        return $this->extGState(['BM' => new Name($mode->value)]);
    }

    /**
     * @param int $mode 0 paints every component (knockout), 1 only non-zero components.
     */
    public function setOverprint(bool $fill, bool|null $stroke = null, int $mode = 1): self
    {
        return $this->extGState(['op' => $fill, 'OP' => $stroke ?? $fill, 'OPM' => $mode]);
    }

    // ----- Path construction ----------------------------------------------------------------

    public function moveTo(float $x, float $y): self
    {
        $this->ops .= Num::list([$x, $y]) . " m\n";
        $this->hasCurrentPoint = true;

        return $this;
    }

    public function lineTo(float $x, float $y): self
    {
        $this->ops .= Num::list([$x, $y]) . " l\n";
        $this->hasCurrentPoint = true;

        return $this;
    }

    public function curveTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): self
    {
        $this->ops .= Num::list([$x1, $y1, $x2, $y2, $x3, $y3]) . " c\n";
        $this->hasCurrentPoint = true;

        return $this;
    }

    public function closePath(): self
    {
        $this->ops .= "h\n";

        return $this;
    }

    public function rect(float $x, float $y, float $width, float $height): self
    {
        $this->ops .= Num::list([$x, $y, $width, $height]) . " re\n";
        $this->hasCurrentPoint = true;

        return $this;
    }

    public function box(Box $box): self
    {
        return $this->rect($box->x, $box->y, $box->width, $box->height);
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

        return $this
            ->moveTo($x + $r, $y)
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

    public function circle(float $cx, float $cy, float $radius): self
    {
        return $this->ellipse($cx, $cy, $radius, $radius);
    }

    public function ellipse(float $cx, float $cy, float $rx, float $ry): self
    {
        $kx = self::KAPPA * $rx;
        $ky = self::KAPPA * $ry;

        return $this
            ->moveTo($cx + $rx, $cy)
            ->curveTo($cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry)
            ->curveTo($cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy)
            ->curveTo($cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry)
            ->curveTo($cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy)
            ->closePath();
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

        $x = $cx + $radius * cos($angle);
        $y = $cy + $radius * sin($angle);
        if ($this->hasCurrentPoint) {
            $this->lineTo($x, $y);
        } else {
            $this->moveTo($x, $y);
        }

        $k = 4.0 / 3.0 * tan($step / 4.0);
        for ($i = 0; $i < $segments; ++$i) {
            $next = $angle + $step;
            $cos1 = cos($angle);
            $sin1 = sin($angle);
            $cos2 = cos($next);
            $sin2 = sin($next);
            $this->curveTo(
                $cx + $radius * ($cos1 - $k * $sin1),
                $cy + $radius * ($sin1 + $k * $cos1),
                $cx + $radius * ($cos2 + $k * $sin2),
                $cy + $radius * ($sin2 - $k * $cos2),
                $cx + $radius * $cos2,
                $cy + $radius * $sin2,
            );
            $angle = $next;
        }

        return $this;
    }

    // ----- Painting -------------------------------------------------------------------------

    public function fill(bool $evenOdd = false): self
    {
        return $this->paint($evenOdd ? 'f*' : 'f');
    }

    public function stroke(): self
    {
        return $this->paint('S');
    }

    public function closeAndStroke(): self
    {
        return $this->paint('s');
    }

    public function fillAndStroke(bool $evenOdd = false): self
    {
        return $this->paint($evenOdd ? 'B*' : 'B');
    }

    /** Intersects the clip region with the current path and discards the path. */
    public function clip(bool $evenOdd = false): self
    {
        return $this->paint($evenOdd ? "W* n" : "W n");
    }

    /** Ends the path without painting it. */
    public function endPath(): self
    {
        return $this->paint('n');
    }

    // ----- Convenience shapes ---------------------------------------------------------------

    public function line(float $x1, float $y1, float $x2, float $y2): self
    {
        return $this->moveTo($x1, $y1)->lineTo($x2, $y2)->stroke();
    }

    public function fillRect(float $x, float $y, float $width, float $height): self
    {
        return $this->rect($x, $y, $width, $height)->fill();
    }

    public function strokeRect(float $x, float $y, float $width, float $height): self
    {
        return $this->rect($x, $y, $width, $height)->stroke();
    }

    // ----- XObjects and raw access ----------------------------------------------------------

    /**
     * Draws a form or image XObject with the given matrix, inside its own save/restore.
     *
     * @internal
     */
    public function drawXObject(Reference $ref, Matrix $matrix): self
    {
        $name = $this->resources->xObject($ref);
        $this->ops .= 'q ' . $matrix->toPdf() . ' cm /' . $name . " Do Q\n";

        return $this;
    }

    /** Appends raw content-stream syntax. A newline is added. */
    public function raw(string $syntax): self
    {
        $this->ops .= $syntax . "\n";

        return $this;
    }

    /** @internal */
    public function resources(): Resources
    {
        return $this->resources;
    }

    /** @internal */
    public function document(): Document
    {
        return $this->document;
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

    // ----- Internals ------------------------------------------------------------------------

    private function paint(string $operator): self
    {
        $this->ops .= $operator . "\n";
        $this->hasCurrentPoint = false;

        return $this;
    }

    private function colorOps(Color $color, bool $fill): string
    {
        $components = Num::list($color->components());

        return match (true) {
            $color instanceof Gray => $components . ($fill ? ' g' : ' G'),
            $color instanceof Rgb => $components . ($fill ? ' rg' : ' RG'),
            $color instanceof Cmyk => $components . ($fill ? ' k' : ' K'),
            $color instanceof Spot => $this->colorSpaceOps($this->spotColorSpace($color), $components, $fill),
            $color instanceof IccColor => $this->colorSpaceOps($this->iccColorSpace($color), $components, $fill),
            default => throw new InvalidArgumentException('Unsupported colour type ' . $color::class),
        };
    }

    private function colorSpaceOps(Reference $colorSpace, string $components, bool $fill): string
    {
        $name = $this->resources->colorSpace($colorSpace);

        return '/' . $name . ($fill ? ' cs ' : ' CS ') . $components . ($fill ? ' scn' : ' SCN');
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
                    'Domain' => [0, 1],
                    'C0' => $white,
                    'C1' => $alternate->components(),
                    'N' => 1,
                ]),
            ];
        });
    }

    private function iccColorSpace(IccColor $color): Reference
    {
        $profile = $color->profile;
        $stream  = $this->document->shared(
            'iccstream:' . $profile->key(),
            static fn(): Stream => new Stream(Dictionary::of(['N' => $profile->componentCount]), $profile->data),
        );

        return $this->document->shared(
            'icc:' . $profile->key(),
            static fn(): array => [new Name('ICCBased'), $stream],
        );
    }

    private function shadingRef(Gradient $gradient): Reference
    {
        return $this->document->shared('sh:' . $gradient->key(), static fn(): Dictionary => $gradient->toShading());
    }

    private function patternName(Gradient $gradient): string
    {
        $shading = $this->shadingRef($gradient);
        $matrix  = $this->ctm;
        $pattern = $this->document->shared(
            'pat:' . $gradient->key() . ':' . $matrix->toPdf(),
            static fn(): Dictionary => Dictionary::of([
                'PatternType' => 2,
                'Shading' => $shading,
                'Matrix' => $matrix->toArray(),
            ]),
        );

        return $this->resources->pattern($pattern);
    }

    /** @param array<string, mixed> $entries */
    private function extGState(array $entries): self
    {
        $dict = Dictionary::of(['Type' => new Name('ExtGState'), ...$entries]);
        $ref  = $this->document->shared('gs:' . \PhpPdf\Core\Serializer::serialize($dict), static fn(): Dictionary => $dict);
        $this->ops .= '/' . $this->resources->extGState($ref) . " gs\n";

        return $this;
    }
}
