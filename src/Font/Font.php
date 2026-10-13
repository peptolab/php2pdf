<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use Override;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Core\DeferredInterface;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Objects;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\OpenType\OpenTypeFont;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_values;
use function count;
use function hash;
use function implode;
use function max;
use function mb_chr;
use function pack;
use function round;
use function sprintf;

/**
 * A font embedded in one document. Text is shaped to glyph ids; the glyphs used are tracked so
 * the font program can be subset when the document closes.
 *
 * Metrics are returned in points for a given size. Internally advances are in thousandths of an
 * em, the same resolution the PDF widths array has, so layout and viewer agree exactly.
 *
 * @api
 */
final class Font implements PdfFontInterface, DeferredInterface
{
    /** Shaped runs kept for reuse; cleared when full so free text cannot grow it without bound. */
    /** How many texts a font remembers having checked for missing glyphs and scripts. */
    private const int CHECKED_TEXTS = 10_000;

    private readonly Reference $reference;

    /** @var array<string, true> hashes of the shaping keys of texts already checked in this document */
    private array $checked = [];

    /** @var array<int, true> glyph ids used */
    private array $used = [0 => true];

    /**
     * Glyph id => the code it is written with: codes are given in the order glyphs are first
     * written, from 0 for .notdef and 1 for the space, so a subset holds only the glyphs used (and
     * the space) and its glyph ids are the codes.
     *
     * @var array<int, int>
     */
    private array $codes = [0 => 0];

    /** The text each glyph stands for, for ToUnicode. */
    private TextMapping $text;

    /** @var array<int, int> glyph id => advance in thousandths of an em, memoised */
    private array $advances = [];

    private readonly float $scale;

    private readonly GlyphCheck $glyphCheck;

    /** @var array<int, true> code points already reported as missing */
    private array $missing = [];

    private readonly ScriptCheck $scriptCheck;

    private ?OpenTypeShaper $shaper = null;

    /** @var array<string, true> scripts already reported as unshaped */
    private array $unshaped = [];

    /**
     * @internal
     *
     * @throws ParseException
     */
    public function __construct(
        private readonly Objects $objects,
        private readonly FontRegistry $registry,
        private readonly FontOptions $options,
        private readonly OpenTypeFont $program,
    ) {
        if (! $program->hasOutlines()) {
            $bitmaps = array_values(array_filter(
                ['sbix', 'CBDT', 'EBDT'],
                /** @throws ParseException */
                static fn(string $tag): bool => null !== $program->table($tag),
            ));
            throw new ParseException(sprintf(
                'Font "%s" has no outlines (glyf, CFF or CFF2)%s, so it cannot be embedded.',
                $program->postScriptName(),
                [] === $bitmaps ? '' : ': its glyphs are ' . implode(', ', $bitmaps) . ' bitmaps',
            ));
        }
        $this->glyphCheck  = $options->glyphCheck;
        $this->scriptCheck = $options->scriptCheck;
        $this->reference   = $objects->allocate();
        $this->scale       = 1000.0 / $program->unitsPerEm;
        $this->text        = new TextMapping();
        $space             = $program->glyphId(0x20);
        if (0 !== $space) {
            $this->codes[$space] = CodeSpace::SPACE;
        }
        $objects->defer($this);
        if ($program->hasColrVersion1Glyphs()) {
            $this->registry->warn(sprintf(
                'Font "%s" has colour glyphs defined only in COLR version 1; they are drawn in one colour.',
                $program->postScriptName(),
            ));
        }
    }

    /** Advance of a glyph in thousandths of an em, rounded as the PDF widths array will carry it. */
    #[Override]
    public function advance(int $glyphId): int
    {
        return $this->advances[$glyphId] ??= (int) round($this->program->advance($glyphId) * $this->scale);
    }

    #[Override]
    public function ascender(float $size): float
    {
        return $this->program->metrics->scale($this->program->metrics->ascender, $size);
    }

    #[Override]
    public function capHeight(float $size): float
    {
        return $this->program->metrics->scale($this->program->metrics->capHeight, $size);
    }

    /** @throws ParseException */
    #[Override]
    public function colourLayers(GlyphRun $run): array
    {
        if (! $this->program->hasColourLayers()) {
            return [];
        }
        $layers = [];
        foreach ($run->glyphs as $index => $glyph) {
            $list = [];
            foreach ($this->program->colourLayers($glyph->id) as [$layer, $palette]) {
                $colour = 0xFFFF === $palette ? null : $this->program->paletteColour($palette);
                $list[] = [
                    $layer,
                    null === $colour ? null : new Rgb($colour[0] / 255, $colour[1] / 255, $colour[2] / 255),
                    null === $colour ? 1.0 : $colour[3] / 255,
                ];
            }
            if ([] !== $list) {
                $layers[$index] = $list;
            }
        }

        return $layers;
    }

    /** Negative: distance below the baseline. */
    #[Override]
    public function descender(float $size): float
    {
        return $this->program->metrics->scale($this->program->metrics->descender, $size);
    }

    /**
     * @internal
     *
     * @param list<int> $glyphIds
     */
    #[Override]
    public function encode(array $glyphIds): string
    {
        // A font with more glyphs than the compact codes reach keeps two-byte codes.
        $compact = CodeSpace::fits($this->program->numGlyphs);
        $bytes   = '';
        foreach ($glyphIds as $id) {
            $code = $this->codes[$id] ?? null;
            if (null === $code) {
                $code             = count($this->codes);
                $this->codes[$id] = $code;
            }
            $bytes .= $compact ? CodeSpace::bytes($code) : pack('n', $code);
        }

        return $bytes;
    }

    /**
     * @internal
     *
     * @throws ParseException
     */
    #[Override]
    public function finalize(): void
    {
        // A glyph marked used without being written (a piece whose codes another run wrote) still
        // gets a code, after the others.
        foreach ($this->used as $id => $_) {
            $this->codes[$id] ??= count($this->codes);
        }
        // Every glyph with text was marked used, so has a code.
        $toUnicode = [];
        foreach ($this->text->map() as $id => $text) {
            $toUnicode[$this->codes[$id] ?? 0] = $text;
        }
        (new Type0FontWriter($this->program, $this->objects, $this->registry, $this->advance(...)))->write(
            $this->reference,
            array_keys($this->codes),
            $toUnicode,
        );
    }

    #[Override]
    public function hasGlyph(int $codePoint): bool
    {
        return 0 !== $this->program->glyphId($codePoint);
    }

    #[Override]
    public function lineGap(float $size): float
    {
        return $this->program->metrics->scale($this->program->metrics->lineGap, $size);
    }

    /**
     * Records the glyphs of a run as used, and whether the font's ToUnicode map gives back the
     * run's text.
     *
     * @internal
     */
    #[Override]
    public function markUsed(GlyphRun $run): bool
    {
        return $this->text->record($run, $this->used);
    }

    #[Override]
    public function name(): string
    {
        return $this->program->postScriptName();
    }

    /** @internal */
    #[Override]
    public function objects(): Objects
    {
        return $this->objects;
    }

    public function program(): OpenTypeFont
    {
        return $this->program;
    }

    /** @internal */
    #[Override]
    public function reference(): Reference
    {
        return $this->reference;
    }

    /**
     * Maps UTF-8 text to glyphs, applies the font's substitutions for the active features (the
     * standard ligatures by default), then kerns. Characters the font lacks become .notdef.
     *
     * @throws ParseException
     */
    #[Override]
    public function shape(string $text, ?TextOptions $options = null): GlyphRun
    {
        $options  ??= TextOptions::default();
        $rtl      = TextDirection::RightToLeft === $options->direction;
        $features = [...$options->activeFeatures(), ...($rtl ? ['rtla', 'rtlm'] : ['ltra', 'ltrm'])];
        $engine   = null === $this->options->complexShaper ? '' : $this->options->complexShaper::class;
        $key      = "{$engine}|{$options->language}|"
        . ($options->kerning ? 'k' : '-')
        . implode(',', $features)
        . "|{$text}";
        // Remember a hash, not the key: keys hold whole stretches of text, often paragraphs.
        $checkedKey = hash('xxh128', $key, binary: true);
        $first      = ! array_key_exists($checkedKey, $this->checked);
        if ($first) {
            $this->checkScript($text);
        }
        $run = $this->program->shapedRun($key);
        if (null === $run) {
            $this->shaper ??= new OpenTypeShaper($this->program, $this->advance(...), $this->options->complexShaper);
            $run          = new GlyphRun($this->shaper->shape($text, $options, $features));
            $this->program->keepShapedRun($key, $run);
        }
        if ($first) {
            if (count($this->checked) >= self::CHECKED_TEXTS) {
                $this->checked = [];
            }
            $this->checked[$checkedKey] = true;
            $this->reportMissing($run);
        }

        return $run;
    }

    #[Override]
    public function strikeoutPosition(float $size): float
    {
        return $this->program->metrics->scale($this->program->metrics->strikeoutPosition, $size);
    }

    #[Override]
    public function strikeoutSize(float $size): float
    {
        return max(0.1, $this->program->metrics->scale($this->program->metrics->strikeoutSize, $size));
    }

    /** Underline offset from the baseline (negative is below). */
    #[Override]
    public function underlinePosition(float $size): float
    {
        return $this->program->metrics->scale($this->program->metrics->underlinePosition, $size);
    }

    #[Override]
    public function underlineThickness(float $size): float
    {
        return max(0.1, $this->program->metrics->scale($this->program->metrics->underlineThickness, $size));
    }

    /**
     * Width of text in points at the given size, including kerning, spacing and tracking.
     *
     * @throws ParseException
     */
    #[Override]
    public function width(string|GlyphRun $text, float $size, ?TextOptions $options = null): float
    {
        $options ??= TextOptions::default();

        return ($text instanceof GlyphRun ? $text : $this->shape($text, $options))->measure($size, $options);
    }

    /** @internal */
    #[Override]
    public function wordSpaceGlyph(): ?int
    {
        $space = $this->program->glyphId(0x20);

        return 0 !== $space && CodeSpace::fits($this->program->numGlyphs) ? $space : null;
    }

    #[Override]
    public function xHeight(float $size): float
    {
        return $this->program->metrics->scale($this->program->metrics->xHeight, $size);
    }

    /**
     * Applies the document's script check to text in a script that needs shaping the library
     * cannot do yet.
     *
     * @throws PdfException under ScriptCheck::Error
     */
    private function checkScript(string $text): void
    {
        if (ScriptCheck::Ignore === $this->scriptCheck || null !== $this->options->complexShaper) {
            return;
        }
        $found = ComplexScripts::find($text);
        if (null === $found || array_key_exists($found[1], $this->unshaped)) {
            return;
        }
        [$codePoint, $script] = $found;
        $what = sprintf(
            '%s text (U+%04X) needs shaping that Php2Pdf cannot do yet, so it would be set wrongly in font "%s"',
            $script,
            $codePoint,
            $this->program->postScriptName(),
        );
        if (ScriptCheck::Error === $this->scriptCheck) {
            throw new PdfException("{$what}. Set DocumentOptions::\$scriptCheck to Warn or Ignore to set it unshaped.");
        }
        $this->unshaped[$script] = true;
        $this->registry->warn("{$what}; it is set unshaped.");
    }

    /**
     * Applies the document's glyph check to a character this font has no glyph for.
     *
     * @throws PdfException under GlyphCheck::Error
     */
    private function missingGlyph(string $char, int $codePoint): void
    {
        if (GlyphCheck::Ignore === $this->glyphCheck || array_key_exists($codePoint, $this->missing)) {
            return;
        }
        $what = sprintf(
            'Font "%s" has no glyph for "%s" (U+%04X)',
            $this->program->postScriptName(),
            $char,
            $codePoint,
        );
        if (GlyphCheck::Error === $this->glyphCheck) {
            throw new PdfException("{$what}.");
        }
        $this->missing[$codePoint] = true;
        $this->registry->warn("{$what}; it is drawn as .notdef.");
    }

    /**
     * Reports the characters a run shows with the missing glyph, as the glyph check says.
     *
     * @throws PdfException when the glyph check is Error
     */
    private function reportMissing(GlyphRun $run): void
    {
        foreach ($run->glyphs as $glyph) {
            if (0 !== $glyph->id || $glyph->codePoint < 0) {
                continue;
            }
            $this->missingGlyph((string) mb_chr($glyph->codePoint, encoding: 'UTF-8'), $glyph->codePoint);
        }
    }
}
