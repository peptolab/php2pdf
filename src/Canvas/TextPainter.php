<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

use Closure;
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
final class TextPainter
{
    /**
     * Begins content that stands for no text: colour glyph layers, drawn over a base glyph that
     * already carries the text, so extraction and search find each character once.
     */
    private const string NO_TEXT = '/Span <</ActualText ()>> BDC';

    /**
     * The last text state show() wrote out as operators, kept so each run does not format them
     * again: the Tf operator for $lastFont at $lastSize, and in $lastRest the Tc, Tz, Ts and Tr
     * operators for $lastOptions and the Tw operator for $lastWordSpace, to six decimals as it adds
     * up over a line's spaces. The stream still decides what is written; these are only its inputs.
     */
    private ?PdfFontInterface $lastFont = null;

    private ?float $lastSize = null;

    private string $lastTf = '';

    private ?TextOptions $lastOptions = null;

    private float $lastWordSpace = 0.0;

    /** @var array{Tc: string, Tz: string, Ts: string, Tr: string, Tw: string} */
    private array $lastRest = ['Tc' => '', 'Tz' => '', 'Ts' => '', 'Tr' => '', 'Tw' => '0 Tw'];

    public function __construct(
        private readonly ContentStream $stream,
        private readonly Resources $resources,
        private readonly Objects $objects,
        /** @var Closure(): TextFragments the document's, asked for when text is first drawn */
        private readonly Closure $fragments,
    ) {}

    /**
     * How far word spacing moves the glyph after $glyphs: $wordSpace, scaled horizontally, after
     * each of them the font's word spacing moves.
     *
     * @param list<Glyph> $glyphs
     */
    private static function spaced(array $glyphs, PdfFontInterface $font, float $wordSpace, TextOptions $options): float
    {
        $space = 0.0 === $wordSpace ? null : $font->wordSpaceGlyph();
        if (null === $space) {
            return 0.0;
        }
        $count = 0;
        foreach ($glyphs as $glyph) {
            $count += $space === $glyph->id ? 1 : 0;
        }

        return ($count * $wordSpace * $options->horizontalScale) / 100.0;
    }

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
     * Shows a shaped run with the given text matrix, colour glyphs as their layers. The layers
     * are marked as standing for no text. A run joined from $pieces is shown from them, as
     * Canvas::showRunPieces() describes, unless it has colour glyphs, and with their word spacing.
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
        ?RunPieces $pieces = null,
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
        if ([] === $layers) {
            $this->show($run, $font, $size, $textMatrix, $options, [], $pieces);

            return;
        }
        $this->show($run, $font, $size, $textMatrix, $options, array_keys($layers), $pieces);
        $wordSpace = $pieces->wordSpace ?? 0.0;
        $this->stream->append(self::NO_TEXT);
        foreach ($layers as $index => $list) {
            $before = array_slice($run->glyphs, offset: 0, length: $index);
            $origin = Matrix::translation(
                $font->width(new GlyphRun($before), $size, $options)
                    + self::spaced($before, $font, $wordSpace, $options),
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
                $this->show($layer, $font, $size, $origin, $options, [], $pieces);
                $canvas->restore();
            }
        }
        $this->stream->append('EMC');
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
     * are drawn over them) are shown invisibly, so they keep their place and their text. A run
     * joined from $pieces is shown from them where TextFragments::show() can, and with their word
     * spacing.
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
        ?RunPieces $pieces = null,
    ): void {
        // The last font shown was checked when it was first shown: textState() keeps it after.
        if ($font !== $this->lastFont) {
            $this->objects->assertOwns($font->objects(), 'A font');
        }
        $state    = $this->textState($font, $size, $options, $pieces->wordSpace ?? 0.0);
        $ops      = "BT{$this->stream->changed($state)}";
        $m        = $textMatrix;
        $position = 1.0 === $m->a && 0.0 === $m->b && 0.0 === $m->c && 1.0 === $m->d
            ? Num::format($m->e, 6) . ' ' . Num::format($m->f, 6) . ' Td'
            : "{$m->toPdf()} Tm";
        $shown =
            [] === $hidden && null !== $pieces && [] !== $pieces->runs
                ? ($this->fragments)()->show($pieces->runs, $pieces->lastKernings, $font, $size, $options)
                : null;
        [$operation, $faithful] = $shown ?? [
            TextOperators::show($run, $font, $size, $options, $hidden, $this->stream->current('Tr') ?? '0 Tr'),
            $font->markUsed($run),
        ];
        $text = "{$ops} {$position} {$operation} ET";
        $this->stream->append($faithful ? $text : TextOperators::actualText($run, $options, $text));
    }

    /**
     * The text state operators for a run, by operator.
     *
     * @return array{Tf: string, Tc: string, Tz: string, Ts: string, Tr: string, Tw: string}
     */
    private function textState(PdfFontInterface $font, float $size, TextOptions $options, float $wordSpace): array
    {
        // A font keeps its reference, and the resources keep the name they gave it, so the Tf
        // operator only changes with the font or the size.
        if ($font !== $this->lastFont || $size !== $this->lastSize) {
            $this->lastFont = $font;
            $this->lastSize = $size;
            $this->lastTf   = "/{$this->resources->font($font->reference())} " . Num::format($size) . ' Tf';
        }
        // Lines mostly share one options object; a scaled segment brings a new one that usually
        // carries the same values, so those are compared too.
        $last = $this->lastOptions;
        if (
            $options !== $last
            && (
                null === $last
                || $options->charSpacing !== $last->charSpacing
                || $options->horizontalScale !== $last->horizontalScale
                || $options->rise !== $last->rise
                || $options->renderMode !== $last->renderMode
            )
        ) {
            $this->lastOptions = $options;
            $this->lastRest    = [
                'Tc' => Num::format($options->charSpacing) . ' Tc',
                'Tz' => Num::format($options->horizontalScale) . ' Tz',
                'Ts' => Num::format($options->rise) . ' Ts',
                'Tr' => "{$options->renderMode->value} Tr",
                'Tw' => $this->lastRest['Tw'],
            ];
        }
        if ($wordSpace !== $this->lastWordSpace) {
            $this->lastWordSpace  = $wordSpace;
            $this->lastRest['Tw'] = Num::format($wordSpace, 6) . ' Tw';
        }

        return ['Tf' => $this->lastTf] + $this->lastRest;
    }
}
