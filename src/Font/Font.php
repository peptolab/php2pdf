<?php

declare(strict_types=1);

namespace PhpPdf\Font;

use Override;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Document\DeferredInterface;
use PhpPdf\Document\Document;
use PhpPdf\Font\OpenType\OpenTypeFont;
use PhpPdf\Font\OpenType\TrueTypeSubsetter;

use function array_keys;
use function array_map;
use function array_values;
use function chr;
use function count;
use function implode;
use function max;
use function mb_ord;
use function mb_str_split;
use function md5;
use function ord;
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
final class Font implements FontInterface, DeferredInterface
{
    private readonly Reference $reference;

    /** @var array<int, true> glyph ids used */
    private array $used = [0 => true];

    /** @var array<int, int> glyph id => code point, for ToUnicode */
    private array $glyphToCodePoint = [];

    /** @var array<int, int> glyph id => advance in thousandths of an em, memoised */
    private array $advances = [];

    private readonly float $scale;

    /** @internal */
    public function __construct(
        Document $document,
        private readonly OpenTypeFont $program,
    ) {
        $this->reference = $document->writer()->allocate();
        $this->scale     = 1000.0 / $program->unitsPerEm;
        $document->defer($this);
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

    /** @internal */
    #[Override]
    public function code(int $glyphId): string
    {
        return sprintf('%04X', $glyphId);
    }

    /** Negative: distance below the baseline. */
    #[Override]
    public function descender(float $size): float
    {
        return $this->program->metrics->scale($this->program->metrics->descender, $size);
    }

    /** @internal */
    #[Override]
    public function finalize(Document $document): void
    {
        $writer   = $document->writer();
        $program  = $this->program;
        $baseFont = "{$this->subsetTag()}+{$program->postScriptName()}";
        $cff      = $program->isCff;

        $fontFile = $cff
            ? $writer->add(new Stream(Dictionary::of(['Subtype' => new Name('OpenType')]), $program->data()))
            : $this->subsetStream($document);

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
            $this->used[$glyph->id]             = true;
            $this->glyphToCodePoint[$glyph->id] ??= $glyph->codePoint;
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
     * Maps UTF-8 text to glyphs with kerning. Characters the font lacks become .notdef.
     */
    #[Override]
    public function shape(string $text, ?TextOptions $options = null): GlyphRun
    {
        $kerning = ($options ?? TextOptions::default())->kerning && $this->program->hasKerning();
        $glyphs  = [];
        $prev    = null;
        foreach (mb_str_split($text, length: 1, encoding: 'UTF-8') as $char) {
            $codePoint = mb_ord($char, encoding: 'UTF-8');
            if (false === $codePoint) {
                continue;
            }
            $id = $this->program->glyphId($codePoint);
            if ($kerning && null !== $prev) {
                $kern = $this->program->kerning($prev->id, $id);
                if (0 !== $kern) {
                    $glyphs[count($glyphs) - 1] = new Glyph(
                        $prev->id,
                        $prev->codePoint,
                        $prev->advance,
                        $kern * $this->scale,
                    );
                }
            }
            $prev     = new Glyph($id, $codePoint, $this->advance($id));
            $glyphs[] = $prev;
        }

        return new GlyphRun(array_values($glyphs));
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
