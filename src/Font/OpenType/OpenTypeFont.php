<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use Php2Pdf\Core\Lru;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\GlyphRun;

use function array_key_exists;
use function count;
use function file_get_contents;
use function filemtime;
use function intdiv;
use function is_file;
use function max;
use function min;
use function preg_replace;
use function realpath;
use function sprintf;
use function str_replace;
use function strlen;
use function trim;

/**
 * A parsed TrueType or OpenType font program: metrics, names, the Unicode cmap, advances,
 * kerning and glyph outlines. Immutable and shareable between documents.
 *
 * @api
 */
final class OpenTypeFont
{
    /** Font programs kept between documents: at most this many... */
    private const int CACHE_ENTRIES = 32;

    /** ...holding at most this many bytes of font data. */
    private const int CACHE_BYTES = 64 * 1024 * 1024;

    /** @var Lru<array{0: int, 1: self}>|null path#face => modification time and program */
    private static ?Lru $cache = null;

    /** How many shaped runs a font keeps for reuse. */
    private const int RUN_CACHE_ENTRIES = 10_000;

    /** @var Lru<GlyphRun>|null shaping key => run, shared by every document using the font */
    private ?Lru $runs = null;

    public readonly TableDirectory $tables;
    public readonly Metrics $metrics;
    public readonly int $unitsPerEm;
    public readonly int $numGlyphs;
    public readonly bool $isCff;

    /** @var array<int, int> code point => glyph id */
    public readonly array $cmap;

    /** @var list<int> glyph id => advance width in font units */
    public readonly array $advances;

    /** @var array<int, string> name id => string */
    public readonly array $names;

    /** @var array<int, int>|null legacy kern pairs */
    private ?array $kernPairs = null;

    private ?GposKerning $gpos        = null;
    private bool         $gposChecked = false;

    /** @var array<int, list<array{0: int, 1: int}>>|null base glyph => layers */
    private ?array $colr = null;

    /** The glyph substitutions; false when the font has none, null until read. */
    private Gsub|false|null $gsub = null;

    private ?Gdef $gdef = null;

    /** The glyph positioning; false when the font has none, null until read. */
    private Gpos|false|null $gposTable = null;

    /** @var list<int>|null glyph offsets into glyf (numGlyphs + 1 entries) */
    private ?array $loca = null;

    private ?BinaryReader $hmtx = null;

    private readonly BinaryReader $reader;

    /**
     * @param int $faceIndex the font's index in a collection (0 for a single font)
     *
     * @throws ParseException
     */
    private function __construct(
        string $data,
        public readonly int $faceIndex,
    ) {
        $this->reader = new BinaryReader($data);
        $this->tables = new TableDirectory($this->reader, $faceIndex);
        $this->isCff  = $this->tables->isCff;

        $head             = $this->tables->slice($this->reader, 'head');
        $this->unitsPerEm = max(16, $head->u16(18));
        $this->numGlyphs  = $this->tables->slice($this->reader, 'maxp')->u16(4);
        $this->advances   = $this->parseAdvances();
        $this->cmap       = $this->tables->has('cmap')
            ? CmapTable::parse($this->tables->slice($this->reader, 'cmap'))
            : [];
        $this->names = $this->tables->has('name')
            ? NameTable::parse($this->tables->slice($this->reader, 'name'))
            : [];
        $parser        = new MetricsParser($this);
        $this->metrics = $parser->parse($head);
    }

    /**
     * Empties the cache of parsed font files that {@see fromFile()} shares between documents.
     */
    public static function clearCache(): void
    {
        self::$cache?->clear();
    }

    /** @throws ParseException */
    public static function fromData(string $data, int $faceIndex = 0): self
    {
        return new self($data, $faceIndex);
    }

    /**
     * Loads and parses a font file. Parsed files are shared between documents in the same
     * process: the 32 most recently used, up to 64 MiB of font data, and reloaded when the file
     * changes.
     *
     * @throws IoException
     * @throws ParseException
     */
    public static function fromFile(string $path, int $faceIndex = 0): self
    {
        $real = realpath($path);
        if (false === $real || ! is_file($real)) {
            throw new IoException(sprintf('Font file "%s" not found.', $path));
        }
        $cache    = self::cache();
        $key      = "{$real}#{$faceIndex}";
        $modified = (int) filemtime($real);
        $cached   = $cache->get($key);
        if (null !== $cached && $cached[0] === $modified) {
            return $cached[1];
        }
        $data = file_get_contents($real);
        if (false === $data) {
            throw new IoException(sprintf('Cannot read font file "%s".', $path));
        }
        $font = new self($data, $faceIndex);
        $cache->set($key, [$modified, $font], strlen($data));

        return $font;
    }

    /** @return Lru<array{0: int, 1: self}> */
    private static function cache(): Lru
    {
        return self::$cache ??= new Lru(self::CACHE_ENTRIES, self::CACHE_BYTES);
    }

    /** Advance width of a glyph in font units. */
    public function advance(int $glyphId): int
    {
        return $this->advances[$glyphId] ?? $this->advances[count($this->advances) - 1] ?? 0;
    }

    /**
     * The colour layers of a glyph from the COLR (version 0) table: each layer glyph with its
     * CPAL palette entry, 0xFFFF meaning the text colour. Empty for a glyph without layers.
     *
     * @return list<array{0: int, 1: int}>
     *
     * @throws ParseException
     *
     * @internal
     */
    public function colourLayers(int $glyph): array
    {
        $this->colr ??= $this->readColr();

        return $this->colr[$glyph] ?? [];
    }

    /**
     * Component glyph ids of a composite glyph, or an empty list for simple glyphs.
     *
     * @return list<int>
     *
     * @throws ParseException
     *
     * @internal
     */
    public function componentGlyphs(int $glyphId): array
    {
        $data = $this->glyphData($glyphId);
        if (strlen($data) < 12) {
            return [];
        }
        $r = new BinaryReader($data);
        if ($r->i16(0) >= 0) {
            return [];
        }
        $components = [];
        $offset     = 10;
        do {
            if (($offset + 4) > $r->length) {
                break;
            }
            $flags        = $r->u16($offset);
            $components[] = $r->u16($offset + 2);
            $offset       += 4 + (0 === ($flags & 0x0001) ? 2 : 4);
            $offset       += match (true) {
                0 !== ($flags & 0x0008) => 2,
                0 !== ($flags & 0x0040) => 4,
                0 !== ($flags & 0x0080) => 8,
                default => 0,
            };
        } while (0 !== ($flags & 0x0020));

        return $components;
    }

    public function data(): string
    {
        return $this->reader->data;
    }

    public function familyName(): string
    {
        return (
            $this->names[NameTable::TYPOGRAPHIC_FAMILY] ?? $this->names[NameTable::FAMILY] ?? $this->postScriptName()
        );
    }

    public function fullName(): string
    {
        return $this->names[NameTable::FULL_NAME] ?? trim("{$this->familyName()} {$this->subfamilyName()}");
    }

    /**
     * The font's glyph definitions (GDEF), synthesised from characters when it has none.
     *
     * @throws ParseException
     *
     * @internal
     */
    public function gdef(): Gdef
    {
        return $this->gdef ??= new Gdef($this->tableReader('GDEF'), $this->cmap);
    }

    /**
     * Raw glyf data for a glyph (empty for glyphs without outlines).
     *
     * @throws ParseException
     *
     * @internal
     */
    public function glyphData(int $glyphId): string
    {
        $loca  = $this->loca();
        $start = $loca[$glyphId] ?? null;
        $end   = $loca[$glyphId + 1] ?? null;
        if ($glyphId < 0 || null === $start || null === $end || $end <= $start) {
            return '';
        }
        $glyf = $this->tables->get('glyf');

        return $this->reader->bytes($glyf['offset'] + $start, $end - $start);
    }

    /** The glyph for a code point, or 0 (.notdef) when the font lacks it. */
    public function glyphId(int $codePoint): int
    {
        return $this->cmap[$codePoint] ?? 0;
    }

    /**
     * The font's glyph positioning (GPOS), or null when it has none.
     *
     * @throws ParseException
     *
     * @internal
     */
    public function gpos(): ?Gpos
    {
        if (null === $this->gposTable) {
            $reader          = $this->tableReader('GPOS');
            $this->gposTable = null === $reader ? false : new Gpos($reader, $this->gdef());
        }

        return false === $this->gposTable ? null : $this->gposTable;
    }

    /**
     * The font's glyph substitutions (GSUB), or null when it has none.
     *
     * @throws ParseException
     *
     * @internal
     */
    public function gsub(): ?Gsub
    {
        if (null === $this->gsub) {
            $reader     = $this->tableReader('GSUB');
            $this->gsub = null === $reader ? false : new Gsub($reader, $this->gdef());
        }

        return false === $this->gsub ? null : $this->gsub;
    }

    /**
     * Whether the font has colour glyphs drawn from layers (COLR version 0).
     *
     * @throws ParseException
     */
    public function hasColourLayers(): bool
    {
        $this->colr ??= $this->readColr();

        return [] !== $this->colr;
    }

    /**
     * Whether the font defines colour glyphs only as COLR version 1 paint graphs, which are drawn
     * in one colour from their outlines.
     *
     * @throws ParseException
     */
    public function hasColrVersion1Glyphs(): bool
    {
        $colr = $this->tableReader('COLR');

        return null !== $colr && $colr->u16(0) >= 1 && $colr->length >= 18 && 0 !== $colr->u32(14);
    }

    public function hasGlyph(int $codePoint): bool
    {
        return array_key_exists($codePoint, $this->cmap);
    }

    /** @throws ParseException */
    public function hasKerning(): bool
    {
        return null !== $this->gposKerning() || [] !== $this->kernPairs();
    }

    /** Whether the font has outlines (glyf, CFF or CFF2), not just bitmaps. */
    public function hasOutlines(): bool
    {
        return $this->isCff || $this->tables->has('glyf');
    }

    /**
     * Keeps a shaped run for documents that shape the same text with the same options.
     *
     * @internal
     */
    public function keepShapedRun(string $key, GlyphRun $run): void
    {
        $this->runs ??= new Lru(self::RUN_CACHE_ENTRIES);
        $this->runs->set($key, $run);
    }

    /**
     * Kerning adjustment between two glyphs in font units (negative moves them closer).
     *
     * @throws ParseException
     */
    public function kerning(int $left, int $right): int
    {
        $gpos = $this->gposKerning();
        if (null !== $gpos) {
            return $gpos->adjustment($left, $right);
        }

        return $this->kernPairs()[($left << 16) | $right] ?? 0;
    }

    /**
     * Left side bearing of a glyph in font units. Subset fonts must keep it: with the head flag
     * that says it equals the glyph's xMin, renderers position each outline by it.
     *
     * @throws ParseException
     */
    public function leftSideBearing(int $glyphId): int
    {
        $this->hmtx ??= $this->tableReader('hmtx');
        $hmtx       = $this->hmtx;
        if (null === $hmtx || $glyphId < 0) {
            return 0;
        }
        $metrics = count($this->advances);
        $offset  = $glyphId < $metrics ? (4 * $glyphId) + 2 : (4 * $metrics) + (2 * ($glyphId - $metrics));

        return ($offset + 2) <= $hmtx->length ? $hmtx->i16($offset) : 0;
    }

    /**
     * A colour of the first CPAL palette as red, green, blue and alpha (0 to 255), or null.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     *
     * @throws ParseException
     *
     * @internal
     */
    public function paletteColour(int $index): ?array
    {
        $cpal = $this->tableReader('CPAL');
        if (null === $cpal || $index >= $cpal->u16(2)) {
            return null;
        }
        $record = $cpal->u32(8) + (4 * ($cpal->u16(12) + $index));
        if (($record + 4) > $cpal->length) {
            return null;
        }

        return [$cpal->u8($record + 2), $cpal->u8($record + 1), $cpal->u8($record), $cpal->u8($record + 3)];
    }

    public function postScriptName(): string
    {
        $name = $this->names[NameTable::POSTSCRIPT] ?? '';
        if ('' === $name) {
            $name = str_replace(
                ' ',
                replace: '',
                subject: $this->names[NameTable::FULL_NAME] ?? $this->names[NameTable::FAMILY] ?? 'Font',
            );
        }

        return preg_replace('/[^!-~]|[\[\](){}<>\/%]/', replacement: '', subject: $name) ?? 'Font';
    }

    /**
     * A run shaped from this font before, by any document, or null.
     *
     * @internal
     */
    public function shapedRun(string $key): ?GlyphRun
    {
        return $this->runs?->get($key);
    }

    public function subfamilyName(): string
    {
        return $this->names[NameTable::TYPOGRAPHIC_SUBFAMILY] ?? $this->names[NameTable::SUBFAMILY] ?? 'Regular';
    }

    /**
     * The raw bytes of a table, or null when the font lacks it.
     *
     * @throws ParseException
     */
    public function table(string $tag): ?string
    {
        if (! $this->tables->has($tag)) {
            return null;
        }
        $table = $this->tables->get($tag);

        return $this->reader->bytes($table['offset'], $table['length']);
    }

    /**
     * A reader over one table, or null when the font lacks it.
     *
     * @internal
     *
     * @throws ParseException
     */
    public function tableReader(string $tag): ?BinaryReader
    {
        return $this->tables->has($tag) ? $this->tables->slice($this->reader, $tag) : null;
    }

    /** @throws ParseException */
    private function gposKerning(): ?GposKerning
    {
        if (! $this->gposChecked) {
            $this->gposChecked = true;
            $reader            = $this->tableReader('GPOS');
            if (null !== $reader) {
                $gpos = new GposKerning($reader);
                if ($gpos->hasPairs()) {
                    $this->gpos = $gpos;
                }
            }
        }

        return $this->gpos;
    }

    /**
     * @return array<int, int>
     *
     * @throws ParseException
     */
    private function kernPairs(): array
    {
        return $this->kernPairs ??= $this->tables->has('kern')
            ? KernTable::parse($this->tables->slice($this->reader, 'kern'))
            : [];
    }

    /**
     * @return list<int>
     *
     * @throws ParseException
     */
    private function loca(): array
    {
        if (null !== $this->loca) {
            return $this->loca;
        }
        if ($this->isCff || ! $this->tables->has('loca') || ! $this->tables->has('glyf')) {
            return $this->loca = [];
        }
        $long   = 0 !== $this->tables->slice($this->reader, 'head')->i16(50);
        $loca   = $this->tables->slice($this->reader, 'loca');
        $count  = min($this->numGlyphs + 1, intdiv($loca->length, $long ? 4 : 2));
        $values = $long ? $loca->u32List(0, $count) : $loca->u16List(0, $count);
        if (! $long) {
            foreach ($values as $i => $v) {
                $values[$i] = $v * 2;
            }
        }

        return $this->loca = $values;
    }

    /**
     * @return list<int>
     *
     * @throws ParseException
     */
    private function parseAdvances(): array
    {
        $metrics  = $this->tables->slice($this->reader, 'hhea')->u16(34);
        $hmtx     = $this->tables->slice($this->reader, 'hmtx');
        $count    = min($metrics, intdiv($hmtx->length, num2: 4));
        $advances = [];
        for ($i = 0; $i < $count; ++$i) {
            $advances[] = $hmtx->u16(4 * $i);
        }

        return [] === $advances ? [$this->unitsPerEm] : $advances;
    }

    /**
     * The layers of every base glyph in the COLR table (version 0 records).
     *
     * @return array<int, list<array{0: int, 1: int}>>
     *
     * @throws ParseException
     */
    private function readColr(): array
    {
        $colr = $this->tableReader('COLR');
        if (null === $colr || $colr->length < 14) {
            return [];
        }
        $bases    = $colr->u16(2);
        $baseAt   = $colr->u32(4);
        $layerAt  = $colr->u32(8);
        $layerMax = $colr->u16(12);
        $layers   = [];
        for ($k = 0; $k < $bases; ++$k) {
            $record = $baseAt + (6 * $k);
            if (($record + 6) > $colr->length) {
                break;
            }
            $first = $colr->u16($record + 2);
            $count = $colr->u16($record + 4);
            $list  = [];
            for ($l = $first; $l < ($first + $count) && $l < $layerMax; ++$l) {
                $list[] = [$colr->u16($layerAt + (4 * $l)), $colr->u16($layerAt + (4 * $l) + 2)];
            }
            $layers[$colr->u16($record)] = $list;
        }

        return $layers;
    }
}
