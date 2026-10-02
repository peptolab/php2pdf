<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use IntlChar;
use Override;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Document\DeferredInterface;
use Php2Pdf\Document\Document;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\OpenType\Cff\CffSubsetter;
use Php2Pdf\Font\OpenType\Cff\CffTable;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2Pdf\Font\OpenType\TrueTypeSubsetter;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_reverse;
use function array_slice;
use function array_values;
use function chr;
use function class_exists;
use function count;
use function implode;
use function max;
use function mb_chr;
use function mb_ord;
use function mb_str_split;
use function md5;
use function ord;
use function pack;
use function preg_match;
use function round;
use function sort;
use function sprintf;
use function strlen;

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
    private const int SHAPE_CACHE_SIZE = 10_000;

    private readonly Reference $reference;

    /** @var array<string, GlyphRun> kerning flag and text => shaped run */
    private array $shapes = [];

    /** @var array<int, true> glyph ids used */
    private array $used = [0 => true];

    /** @var array<int, list<int>> glyph id => the code points it stands for, for ToUnicode */
    private array $glyphToCodePoint = [];

    /** @var array<int, int> glyph id => advance in thousandths of an em, memoised */
    private array $advances = [];

    private readonly float $scale;

    private readonly FontRegistry $registry;
    private readonly GlyphCheck $glyphCheck;

    /** @var array<int, true> code points already reported as missing */
    private array $missing = [];

    private readonly ScriptCheck $scriptCheck;

    /** @var array<string, true> scripts already reported as unshaped */
    private array $unshaped = [];

    /** @internal */
    public function __construct(
        private readonly Document $document,
        private readonly OpenTypeFont $program,
    ) {
        if (! $program->hasOutlines()) {
            $bitmaps = array_values(array_filter(
                ['sbix', 'CBDT', 'EBDT'],
                static fn(string $tag): bool => null !== $program->table($tag),
            ));
            throw new ParseException(sprintf(
                'Font "%s" has no outlines (glyf, CFF or CFF2)%s, so it cannot be embedded.',
                $program->postScriptName(),
                [] === $bitmaps ? '' : ': its glyphs are ' . implode(', ', $bitmaps) . ' bitmaps',
            ));
        }
        $this->registry    = $document->fonts();
        $this->glyphCheck  = $document->options()->glyphCheck;
        $this->scriptCheck = $document->options()->scriptCheck;
        $this->reference   = $document->writer()->allocate();
        $this->scale       = 1000.0 / $program->unitsPerEm;
        $document->defer($this);
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

    /** @internal */
    #[Override]
    public function document(): Document
    {
        return $this->document;
    }

    /**
     * @internal
     *
     * @param list<int> $glyphIds
     */
    #[Override]
    public function encode(array $glyphIds): string
    {
        return pack('n*', ...$glyphIds);
    }

    /** @internal */
    #[Override]
    public function finalize(Document $document): void
    {
        $writer   = $document->writer();
        $program  = $this->program;
        $baseFont = "{$this->subsetTag()}+{$program->postScriptName()}";
        $cff      = $program->isCff;

        $fontFile = $cff ? $this->cffStream($document) : $this->subsetStream($document);

        $descriptor = $writer->add(Dictionary::of([
            'Type'                           => new Name('FontDescriptor'),
            'FontName'                       => new Name($baseFont),
            'Flags'                          => $this->flags(),
            'FontBBox'                       => array_map(
                fn(int $v): int => (int) round($v * $this->scale),
                $program->metrics->bbox,
            ),
            'ItalicAngle'                    => $program->metrics->italicAngle,
            'Ascent'                         => (int) round($program->metrics->ascender * $this->scale),
            'Descent'                        => (int) round($program->metrics->descender * $this->scale),
            'CapHeight'                      => (int) round($program->metrics->capHeight * $this->scale),
            'XHeight'                        => (int) round($program->metrics->xHeight * $this->scale),
            'StemV'                          => $this->stemV(),
            $cff ? 'FontFile3' : 'FontFile2' => $fontFile,
        ]));

        $descendant = $writer->add(Dictionary::of([
            'Type'           => new Name('Font'),
            'Subtype'        => new Name($cff ? 'CIDFontType0' : 'CIDFontType2'),
            'BaseFont'       => new Name($baseFont),
            'CIDSystemInfo'  => Dictionary::of(['Registry' => 'Adobe', 'Ordering' => 'Identity', 'Supplement' => 0]),
            'FontDescriptor' => $descriptor,
            'DW'             => 1000,
            'W'              => $this->widths(),
            'CIDToGIDMap'    => $cff ? null : new Name('Identity'),
        ]));
        $toUnicode = $writer->add(Stream::of(ToUnicode::build($this->glyphToCodePoint)));

        $writer->write($this->reference, Dictionary::of([
            'Type'            => new Name('Font'),
            'Subtype'         => new Name('Type0'),
            'BaseFont'        => new Name($baseFont),
            'Encoding'        => new Name('Identity-H'),
            'DescendantFonts' => [$descendant],
            'ToUnicode'       => $toUnicode,
        ]));
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
     * Records the glyphs of a run as used.
     *
     * @internal
     */
    #[Override]
    public function markUsed(GlyphRun $run): void
    {
        foreach ($run->glyphs as $glyph) {
            $this->used[$glyph->id] = true;
            if (0 === $glyph->id) {
                continue;
            }
            if ($glyph->codePoint < 0) {
                continue;
            }
            $this->glyphToCodePoint[$glyph->id] ??= $glyph->codePoints();
        }
    }

    #[Override]
    public function name(): string
    {
        return $this->program->postScriptName();
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
     */
    #[Override]
    public function shape(string $text, ?TextOptions $options = null): GlyphRun
    {
        $options  ??= TextOptions::default();
        $rtl      = TextDirection::RightToLeft === $options->direction;
        $features = [...$options->activeFeatures(), ...($rtl ? ['rtla', 'rtlm'] : ['ltra', 'ltrm'])];
        $key      = ($options->kerning ? 'k' : '-') . implode(',', $features) . '|' . $text;
        $cached   = $this->shapes[$key] ?? null;
        if (null !== $cached) {
            return $cached;
        }
        $this->checkScript($text);
        $gdef       = $this->program->gdef();
        $codePoints = $this->codePoints($text);
        $sequence   = [];
        foreach ($codePoints as $codePoint) {
            $shown = $rtl ? $this->mirrored($codePoint) : $codePoint;
            $id    = $this->program->glyphId($shown);
            if (0 === $id) {
                $this->missingGlyph(mb_chr($codePoint, encoding: 'UTF-8'), $codePoint);
            }
            $gdef->learn($id, $codePoint);
            $sequence[] = [$id, [$shown]];
        }
        $gsub   = $this->program->gsub();
        $glyphs = [];
        foreach (ScriptRuns::of($codePoints) as [$script, $start, $length]) {
            $run = array_slice($sequence, $start, $length);
            if (null !== $gsub) {
                $run = $gsub->apply($run, $features, $script);
            }
            foreach ($this->positioned($run, $options, $features, $script) as $glyph) {
                $glyphs[] = $glyph;
            }
        }
        if ($rtl) {
            $glyphs = array_reverse($glyphs);
        }

        if (count($this->shapes) >= self::SHAPE_CACHE_SIZE) {
            $this->shapes = [];
        }

        return $this->shapes[$key] = new GlyphRun($glyphs);
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

    /** Width of text in points at the given size, including kerning, spacing and tracking. */
    #[Override]
    public function width(string|GlyphRun $text, float $size, ?TextOptions $options = null): float
    {
        $options ??= TextOptions::default();

        return ($text instanceof GlyphRun ? $text : $this->shape($text, $options))->measure($size, $options);
    }

    #[Override]
    public function xHeight(float $size): float
    {
        return $this->program->metrics->scale($this->program->metrics->xHeight, $size);
    }

    /**
     * The CFF outlines subset to the glyphs used, or the whole font program when they cannot be
     * subset; the font registry then records a warning. A variable font's CFF2 outlines are
     * converted to CFF at their default instance first; when that fails the font cannot be used,
     * since few readers draw CFF2.
     */
    private function cffStream(Document $document): Reference
    {
        try {
            $data    = CffSubsetter::subsetTable($this->cffTable(), $this->used);
            $subtype = 'CIDFontType0C';
        } catch (PdfException $e) {
            if (null === $this->program->table('CFF ')) {
                throw new ParseException(sprintf(
                    'Variable font "%s" cannot be embedded: its CFF2 outlines could not be converted: %s',
                    $this->program->postScriptName(),
                    $e->getMessage(),
                ), previous: $e);
            }
            $document->fonts()
                ->warn(sprintf(
                    'Font "%s" is embedded whole because its CFF outlines could not be subset: %s',
                    $this->program->postScriptName(),
                    $e->getMessage(),
                ));
            $data    = $this->program->data();
            $subtype = 'OpenType';
        }

        return $document->writer()->add(new Stream(Dictionary::of(['Subtype' => new Name($subtype)]), $data));
    }

    /**
     * The font's CFF outlines: the CFF table, or a variable font's CFF2 outlines at their default
     * instance.
     */
    private function cffTable(): CffTable
    {
        $cff = $this->program->table('CFF ');
        if (null !== $cff) {
            return CffTable::parse($cff);
        }
        $cff2 = $this->program->table('CFF2') ?? throw new ParseException('The font has no CFF or CFF2 table.');

        return CffTable::fromCff2($cff2, $this->program->postScriptName());
    }

    /**
     * Applies the document's script check to text in a script that needs shaping the library
     * cannot do yet.
     *
     * @throws PdfException under ScriptCheck::Error
     */
    private function checkScript(string $text): void
    {
        if (ScriptCheck::Ignore === $this->scriptCheck) {
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
     * The text's code points, normalised for the font when it has combining marks or characters
     * the font lacks.
     *
     * @return list<int>
     */
    private function codePoints(string $text): array
    {
        $codePoints = [];
        $missing    = false;
        foreach (mb_str_split($text, length: 1, encoding: 'UTF-8') as $char) {
            $codePoint = mb_ord($char, encoding: 'UTF-8');
            if (false === $codePoint) {
                continue;
            }
            $codePoints[] = $codePoint;
            $missing      = $missing || 0 === $this->program->glyphId($codePoint);
        }
        if (! $missing && 1 !== preg_match('/\p{M}/u', $text)) {
            return $codePoints;
        }

        return Normaliser::normalise(
            $codePoints,
            fn(int $codePoint): bool => 0 !== $this->program->glyphId($codePoint),
        );
    }

    private function flags(): int
    {
        $metrics = $this->program->metrics;
        $flags   = 32; // Nonsymbolic
        if ($metrics->isFixedPitch) {
            $flags |= 1;
        }
        if ($metrics->isSerif) {
            $flags |= 2;
        }
        if ($metrics->isItalic || 0.0 !== $metrics->italicAngle) {
            $flags |= 64;
        }

        return $flags;
    }

    /**
     * The character a font shows for one set right to left: its mirrored form (")" for "("), when
     * it has one and the font has a glyph for it, as HarfBuzz does.
     */
    private function mirrored(int $codePoint): int
    {
        if (! class_exists(IntlChar::class) || ! IntlChar::isMirrored($codePoint)) {
            return $codePoint;
        }
        $mirror = (int) IntlChar::charMirror($codePoint);

        return 0 !== $this->program->glyphId($mirror) ? $mirror : $codePoint;
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
     * Glyphs for a substituted sequence, positioned by the font's GPOS (kerning when it has a kern
     * feature, mark attachment, and the requested features), else kerned from its kern table. A
     * glyph's kerning carries what positioning adds to its advance; a mark positioning gives no
     * advance takes back its whole drawn width.
     *
     * @param list<array{0: int, 1: list<int>, 2?: int, 3?: int}> $sequence
     * @param list<string>                                        $features
     *
     * @return list<Glyph>
     */
    private function positioned(array $sequence, TextOptions $options, array $features, string $script): array
    {
        $gpos         = $this->program->gpos();
        $gposFeatures = [...($options->kerning ? ['kern'] : []), 'mark', 'mkmk', 'dist', 'abvm', 'blwm', ...$features];
        $positions    = null;
        if (null !== $gpos && $gpos->has($gposFeatures, $script)) {
            $advances = [];
            foreach ($sequence as [$id]) {
                $advances[] = $this->program->advance($id);
            }
            $positions = $gpos->position(
                $sequence,
                $advances,
                $gposFeatures,
                $script,
                TextDirection::RightToLeft === $options->direction,
            );
        }
        $legacyKern =
            $options->kerning
            && (null === $positions || ! ($gpos?->has(['kern'], $script) ?? false))
            && $this->program->hasKerning();
        $glyphs = [];
        foreach ($sequence as $k => [$id, $codePoints]) {
            $advance = $this->advance($id);
            $glyph   = new Glyph($id, $codePoints[0] ?? -1, $advance, 0.0, count($codePoints) > 1 ? $codePoints : []);
            if (null !== $positions) {
                [$xAdvance, , $xOffset, $yOffset] = $positions[$k] ?? [0, 0, 0, 0];
                $font    = $this->program->advance($id);
                $kerning = 0 === $xAdvance && 0 !== $font ? -$advance : ($xAdvance - $font) * $this->scale;
                $glyph   = $glyph->withKerning($kerning)->withOffset($xOffset * $this->scale, $yOffset * $this->scale);
            }
            $previous = $glyphs[$k - 1] ?? null;
            if ($legacyKern && null !== $previous) {
                $kern = $this->program->kerning($previous->id, $id);
                if (0 !== $kern) {
                    $glyphs[$k - 1] = $previous->withKerning($previous->kerning + ($kern * $this->scale));
                }
            }
            $glyphs[] = $glyph;
        }

        return $glyphs;
    }

    private function stemV(): int
    {
        // A common estimate; real stem widths live in the outlines, which readers do not need.
        return (int) round(50 + ($this->program->metrics->weightClass / 65.0) ** 2);
    }

    private function subsetStream(Document $document): Reference
    {
        $data = TrueTypeSubsetter::subset($this->program, $this->used);

        return $document->writer()->add(new Stream(Dictionary::of(['Length1' => strlen($data)]), $data));
    }

    /** Six uppercase letters derived from the glyph set, so identical input yields identical tags. */
    private function subsetTag(): string
    {
        $ids = array_keys($this->used);
        sort($ids);
        $hash = md5($this->program->postScriptName() . ':' . implode(',', $ids), binary: true);
        $tag  = '';
        for ($i = 0; $i < 6; ++$i) {
            $tag .= chr(65 + (ord($hash[$i]) % 26));
        }

        return $tag;
    }

    /**
     * The W array: runs of consecutive glyph ids with their widths.
     *
     * @return list<int|list<int>>
     */
    private function widths(): array
    {
        $ids = array_keys($this->used);
        sort($ids);

        /** @var list<array{0: int, 1: list<int>}> $runs */
        $runs = [];
        $prev = null;
        foreach ($ids as $id) {
            $continues = null !== $prev && $id === ($prev + 1) && [] !== $runs;
            $prev      = $id;
            if ($continues) {
                $runs[count($runs) - 1][1][] = $this->advance($id);
                continue;
            }
            $runs[] = [$id, [$this->advance($id)]];
        }

        $w = [];
        foreach ($runs as [$start, $advances]) {
            $w[] = $start;
            $w[] = $advances;
        }

        return $w;
    }
}
