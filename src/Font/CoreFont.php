<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use Override;
use Php2Pdf\Core\DeferredInterface;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Objects;
use Php2Pdf\Exception\ConformanceException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\Standard\AfmMetrics;

use function array_key_exists;
use function count;
use function max;
use function mb_ord;
use function mb_str_split;
use function pack;
use function sprintf;

/**
 * One of the standard 14 fonts, used in one document. It is written as a simple Type1 font
 * that is not embedded: the reader supplies the outlines, and the widths and kerning come from
 * Adobe's AFM metrics so layout matches what the reader draws.
 *
 * Glyph ids are the font's single-byte codes: WinAnsiEncoding for the Latin fonts, the built-in
 * encoding for Symbol and ZapfDingbats.
 *
 * @api
 */
final class CoreFont implements PdfFontInterface, DeferredInterface
{
    /** AFM files carry no strikeout metrics; these match what OpenType fonts without them get. */
    private const int STRIKEOUT_POSITION = 250;
    private const int STRIKEOUT_SIZE     = 50;

    /** Shaped runs kept for reuse; cleared when full so free text cannot grow it without bound. */
    private const int SHAPE_CACHE_SIZE = 10_000;

    private readonly Reference $reference;

    /** @var array<string, GlyphRun> kerning flag and text => shaped run */
    private array $shapes = [];

    private readonly AfmMetrics $metrics;

    /** The text each code stands for, for ToUnicode. */
    private readonly TextMapping $text;

    /** @internal */
    public function __construct(
        private readonly Objects $objects,
        FontOptions $options,
        private readonly StandardFont $font,
    ) {
        if (null !== $options->embeddingRequiredBy) {
            throw new ConformanceException(sprintf(
                '%s requires every font to be embedded, and the standard font "%s" is not; load a font file instead.',
                $options->embeddingRequiredBy,
                $font->value,
            ));
        }
        $this->metrics   = AfmMetrics::of($font);
        $this->text      = new TextMapping();
        $this->reference = $objects->allocate();
        $objects->defer($this);
    }

    private static function scale(int $units, float $size): float
    {
        return ($units * $size) / 1000.0;
    }

    #[Override]
    public function advance(int $glyphId): int
    {
        return $this->metrics->widths[$glyphId] ?? 0;
    }

    #[Override]
    public function ascender(float $size): float
    {
        return self::scale($this->metrics->ascender, $size);
    }

    #[Override]
    public function capHeight(float $size): float
    {
        return self::scale($this->metrics->capHeight, $size);
    }

    /** The standard fonts have no colour glyphs. */
    #[Override]
    public function colourLayers(GlyphRun $run): array
    {
        return [];
    }

    #[Override]
    public function descender(float $size): float
    {
        return self::scale($this->metrics->descender, $size);
    }

    /**
     * @internal
     *
     * @param list<int> $glyphIds
     */
    #[Override]
    public function encode(array $glyphIds): string
    {
        return pack('C*', ...$glyphIds);
    }

    /** @internal */
    #[Override]
    public function finalize(): void
    {
        $encoding = $this->metrics->encoding;
        $writer   = $this->objects;
        $writer->write($this->reference, Dictionary::of([
            'Type'      => new Name('Font'),
            'Subtype'   => new Name('Type1'),
            'BaseFont'  => new Name($this->font->value),
            'Encoding'  => null === $encoding ? null : new Name($encoding),
            'ToUnicode' => null === $encoding && [] !== $this->text->map()
                ? $writer->add(Stream::of(ToUnicode::build($this->text->map(), 1)))
                : null,
        ]));
    }

    /** The standard font this is. */
    public function font(): StandardFont
    {
        return $this->font;
    }

    /** AFM files have no line gap: the leading is the layout's to choose. */
    #[Override]
    public function hasGlyph(int $codePoint): bool
    {
        return array_key_exists($codePoint, $this->metrics->codes);
    }

    #[Override]
    public function lineGap(float $size): float
    {
        return 0.0;
    }

    /** @internal */
    #[Override]
    public function markUsed(GlyphRun $run): bool
    {
        return $this->text->record($run);
    }

    #[Override]
    public function name(): string
    {
        return $this->font->value;
    }

    /** @internal */
    #[Override]
    public function objects(): Objects
    {
        return $this->objects;
    }

    /** @internal */
    #[Override]
    public function reference(): Reference
    {
        return $this->reference;
    }

    /**
     * Maps UTF-8 text to the font's codes with kerning.
     *
     * @throws PdfException for a character the font's encoding cannot represent
     */
    #[Override]
    public function shape(string $text, ?TextOptions $options = null): GlyphRun
    {
        $metrics = $this->metrics;
        $kerning = ($options ?? TextOptions::default())->kerning;
        $key     = ($kerning ? 'k' : '-') . $text;
        $cached  = $this->shapes[$key] ?? null;
        if (null !== $cached) {
            return $cached;
        }
        $glyphs = [];
        $prev   = null;
        foreach (mb_str_split($text, length: 1, encoding: 'UTF-8') as $index => $char) {
            $codePoint = mb_ord($char, encoding: 'UTF-8');
            if (false === $codePoint) {
                continue;
            }
            $code = $metrics->codes[$codePoint] ?? throw new PdfException(sprintf(
                'The standard font %s cannot show "%s" (U+%04X); %s. Load a font file that has it instead.',
                $this->font->value,
                $char,
                $codePoint,
                $this->font->isSymbolic() ? 'it is not in its character set' : 'it is outside WinAnsiEncoding',
            ));
            $kern = null === $prev || ! $kerning ? 0 : $metrics->kerning[$prev->id][$code] ?? 0;
            if (0 !== $kern && null !== $prev) {
                $glyphs[count($glyphs) - 1] = $prev->withKerning($kern);
            }
            $prev     = new Glyph($code, $codePoint, $this->advance($code), cluster: $index);
            $glyphs[] = $prev;
        }

        if (count($this->shapes) >= self::SHAPE_CACHE_SIZE) {
            $this->shapes = [];
        }

        return $this->shapes[$key] = new GlyphRun($glyphs);
    }

    #[Override]
    public function strikeoutPosition(float $size): float
    {
        return self::scale(self::STRIKEOUT_POSITION, $size);
    }

    #[Override]
    public function strikeoutSize(float $size): float
    {
        return max(0.1, self::scale(self::STRIKEOUT_SIZE, $size));
    }

    #[Override]
    public function underlinePosition(float $size): float
    {
        return self::scale($this->metrics->underlinePosition, $size);
    }

    #[Override]
    public function underlineThickness(float $size): float
    {
        return max(0.1, self::scale($this->metrics->underlineThickness, $size));
    }

    #[Override]
    public function width(string|GlyphRun $text, float $size, ?TextOptions $options = null): float
    {
        $options ??= TextOptions::default();

        return ($text instanceof GlyphRun ? $text : $this->shape($text, $options))->measure($size, $options);
    }

    #[Override]
    public function xHeight(float $size): float
    {
        return self::scale($this->metrics->xHeight, $size);
    }
}
