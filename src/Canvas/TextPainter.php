<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

use Php2Pdf\Core\Num;
use Php2Pdf\Core\Objects;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Fit\FitEngine;
use Php2Pdf\Fit\FitMethod;
use Php2Pdf\Font\Bidi\BidiRuns;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\PdfFontInterface;
use Php2Pdf\Font\TextOptions;
use Php2Pdf\Font\TextRenderMode;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Matrix;

use function array_keys;
use function array_slice;
use function max;
use function min;
use function sprintf;

/**
 * Shows text on a canvas: shaped runs in text objects, writing only the text state that changes;
 * colour glyphs as their layers over an invisible base; and single lines fitted into a box, with
 * underline and strikeout. It is given the canvas for each call rather than holding it, so the
 * two do not keep each other alive.
 *
 * @internal
 */
final readonly class TextPainter
{
    public function __construct(
        private ContentStream $stream,
        private Resources $resources,
        private Objects $objects,
    ) {}

    /** Fits a line of text into a box, positioned, rotated, scaled or shrunk as the options say. */
    public function fitLine(
        Canvas $canvas,
        string $text,
        FontInterface $font,
        float $size,
        Box $box,
        TextlineOptions $options,
    ): TextlinePlacement {
        $run     = BidiRuns::shape($font, $text, $options->text);
        $width   = max(0.01, $font->width($run, $size, $options->text));
        $descent = $font->descender($size);
        $height  = max(0.01, $font->ascender($size) - $descent);

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
        $placement = FitEngine::place($width, $height, $box, $fit, $canvas->origin());
        $matrix    = $placement->matrix;

        $canvas->save();
        if (null !== $placement->clip) {
            $canvas->box($placement->clip)->clip();
        }
        $this->run($canvas, $run, $font, $size, Matrix::translation(0.0, -$descent)->then($matrix), $options->text);
        if ($options->underline) {
            $this->bar($matrix, $width, -$descent + $font->underlinePosition($size), $font->underlineThickness($size));
        }
        if ($options->strikeout) {
            $this->bar($matrix, $width, -$descent + $font->strikeoutPosition($size), $font->strikeoutSize($size));
        }
        $canvas->restore();

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

    /**
     * Shows a shaped run with the given text matrix, colour glyphs as their layers.
     *
     * @throws InvalidArgumentException for a font that is not the library's own
     */
    public function run(
        Canvas $canvas,
        GlyphRun $run,
        FontInterface $font,
        float $size,
        Matrix $textMatrix,
        TextOptions $options,
    ): void {
        if ($run->isEmpty()) {
            return;
        }
        $font = $font instanceof PdfFontInterface
            ? $font
            : throw new InvalidArgumentException(sprintf(
                'Text can only be drawn with fonts from the document\'s FontRegistry, not %s.',
                $font::class,
            ));
        $layers = TextRenderMode::Invisible === $options->renderMode ? [] : $font->colourLayers($run);
        $this->show($run, $font, $size, $textMatrix, $options, array_keys($layers));
        foreach ($layers as $index => $list) {
            $origin = Matrix::translation(
                $font->width(new GlyphRun(array_slice($run->glyphs, offset: 0, length: $index)), $size, $options),
                0.0,
            )->then($textMatrix);
            foreach ($list as [$glyph, $colour, $opacity]) {
                $canvas->save();
                if (null !== $colour) {
                    $canvas->setFillColor($colour);
                }
                if ($opacity < 1.0) {
                    $canvas->setOpacity($opacity);
                }
                $layer = new GlyphRun([new Glyph($glyph, -1, $font->advance($glyph))]);
                $this->show($layer, $font, $size, $origin, $options, []);
                $canvas->restore();
            }
        }
    }

    /** A filled bar in the object space of a fitted text line: an underline or a strikeout. */
    private function bar(Matrix $matrix, float $width, float $y, float $thickness): void
    {
        $this->stream->append(
            "q {$matrix->toPdf()} cm 0 "
                . Num::format($y - ($thickness / 2.0))
                . ' '
                . Num::format($width)
                . ' '
                . Num::format($thickness)
                . ' re f Q',
        );
    }

    /**
     * Shows a run in one text object. Glyphs at the $hidden positions (colour glyphs, whose layers
     * are drawn over them) are shown invisibly, so they keep their place and their text.
     *
     * @param list<int> $hidden
     */
    private function show(
        GlyphRun $run,
        PdfFontInterface $font,
        float $size,
        Matrix $textMatrix,
        TextOptions $options,
        array $hidden,
    ): void {
        $this->objects->assertOwns($font->objects(), 'A font');
        $faithful = $font->markUsed($run);
        $ops      = 'BT';
        $state    = [
            'Tf' => "/{$this->resources->font($font->reference())} " . Num::format($size) . ' Tf',
            'Tc' => Num::format($options->charSpacing) . ' Tc',
            'Tz' => Num::format($options->horizontalScale) . ' Tz',
            'Ts' => Num::format($options->rise) . ' Ts',
            'Tr' => "{$options->renderMode->value} Tr",
        ];
        foreach ($state as $key => $operation) {
            if (! $this->stream->changes($key, $operation)) {
                continue;
            }
            $ops .= " {$operation}";
        }
        $m        = $textMatrix;
        $position = 1.0 === $m->a && 0.0 === $m->b && 0.0 === $m->c && 1.0 === $m->d
            ? Num::format($m->e, 6) . ' ' . Num::format($m->f, 6) . ' Td'
            : "{$m->toPdf()} Tm";
        $text =
            "{$ops} {$position} "
            . TextOperators::show($run, $font, $size, $options, $hidden, $this->stream->current('Tr') ?? '0 Tr')
            . ' ET';
        $this->stream->append($faithful ? $text : TextOperators::actualText($run, $options, $text));
    }
}
