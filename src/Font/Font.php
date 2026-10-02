<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use Override;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Core\DeferredInterface;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Objects;
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
use function array_values;
use function chr;
use function count;
use function implode;
use function max;
use function md5;
use function ord;
use function pack;
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
        return pack('n*', ...$glyphIds);
    }

    /**
     * @internal
     *
     * @throws ParseException
     */
    #[Override]
    public function finalize(): void
    {
        $writer   = $this->objects;
        $program  = $this->program;
        $baseFont = "{$this->subsetTag()}+{$program->postScriptName()}";
        $cff      = $program->isCff;

        $fontFile = $cff ? $this->cffStream() : $this->subsetStream();

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
        $toUnicode = $writer->add(Stream::of(ToUnicode::build($this->text->map())));

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
     * Records the glyphs of a run as used, and whether the font's ToUnicode map gives back the
     * run's text.
     *
     * @internal
     */
    #[Override]
    public function markUsed(GlyphRun $run): bool
    {
        foreach ($run->glyphs as $glyph) {
            $this->used[$glyph->id] = true;
        }

        return $this->text->record($run);
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
        $key      = ($options->kerning ? 'k' : '-') . implode(',', $features) . '|' . $text;
        $cached   = $this->shapes[$key] ?? null;
        if (null !== $cached) {
            return $cached;
        }
        $this->checkScript($text);
        $this->shaper ??= new OpenTypeShaper(
            $this->program,
            $this->advance(...),
            $this->missingGlyph(...),
            $this->options->complexShaper,
        );
        $glyphs = $this->shaper->shape($text, $options, $features);

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
     *
     * @throws ParseException
     */
    private function cffStream(): Reference
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
            $this->registry
                ->warn(sprintf(
                    'Font "%s" is embedded whole because its CFF outlines could not be subset: %s',
                    $this->program->postScriptName(),
                    $e->getMessage(),
                ));
            $data    = $this->program->data();
            $subtype = 'OpenType';
        }

        return $this->objects->add(new Stream(Dictionary::of(['Subtype' => new Name($subtype)]), $data));
    }

    /**
     * The font's CFF outlines: the CFF table, or a variable font's CFF2 outlines at their default
     * instance.
     *
     * @throws ParseException
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

    private function stemV(): int
    {
        // A common estimate; real stem widths live in the outlines, which readers do not need.
        return (int) round(50 + ($this->program->metrics->weightClass / 65.0) ** 2);
    }

    /** @throws ParseException */
    private function subsetStream(): Reference
    {
        $data = TrueTypeSubsetter::subset($this->program, $this->used);

        return $this->objects->add(new Stream(Dictionary::of(['Length1' => strlen($data)]), $data));
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
