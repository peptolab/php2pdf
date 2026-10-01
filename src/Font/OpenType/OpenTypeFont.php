<?php

declare(strict_types=1);

namespace PhpPdf\Font\OpenType;

use PhpPdf\Exception\IoException;

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
    /** @var array<string, self> */
    private static array $cache = [];

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

    /** @var list<int>|null glyph offsets into glyf (numGlyphs + 1 entries) */
    private ?array $loca = null;

    private ?BinaryReader $hmtx = null;

    private readonly BinaryReader $reader;

    private function __construct(string $data, int $faceIndex)
    {
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

    public static function fromData(string $data, int $faceIndex = 0): self
    {
        return new self($data, $faceIndex);
    }

    /**
     * Loads and parses a font file, caching the result per path for the life of the process.
     */
    public static function fromFile(string $path, int $faceIndex = 0): self
    {
        $real = realpath($path);
        if (false === $real || ! is_file($real)) {
            throw new IoException(sprintf('Font file "%s" not found.', $path));
        }
        $key    = "{$real}#{$faceIndex}#" . (string) filemtime($real);
        $cached = self::$cache[$key] ?? null;
        if (null !== $cached) {
            return $cached;
        }
        $data = file_get_contents($real);
        if (false === $data) {
            throw new IoException(sprintf('Cannot read font file "%s".', $path));
        }

        return self::$cache[$key] = new self($data, $faceIndex);
    }

    /** Advance width of a glyph in font units. */
    public function advance(int $glyphId): int
    {
        return $this->advances[$glyphId] ?? $this->advances[count($this->advances) - 1] ?? 0;
    }

    /**
     * Component glyph ids of a composite glyph, or an empty list for simple glyphs.
     *
     * @return list<int>
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

    /** Raw glyf data for a glyph (empty for glyphs without outlines). */
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

    public function hasGlyph(int $codePoint): bool
    {
        return array_key_exists($codePoint, $this->cmap);
    }

    public function hasKerning(): bool
    {
        return null !== $this->gposKerning() || [] !== $this->kernPairs();
    }

    /** Kerning adjustment between two glyphs in font units (negative moves them closer). */
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

    public function subfamilyName(): string
    {
        return $this->names[NameTable::TYPOGRAPHIC_SUBFAMILY] ?? $this->names[NameTable::SUBFAMILY] ?? 'Regular';
    }

    /** The raw bytes of a table, or null when the font lacks it. */
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
     */
    public function tableReader(string $tag): ?BinaryReader
    {
        return $this->tables->has($tag) ? $this->tables->slice($this->reader, $tag) : null;
    }

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

    /** @return array<int, int> */
    private function kernPairs(): array
    {
        return $this->kernPairs ??= $this->tables->has('kern')
            ? KernTable::parse($this->tables->slice($this->reader, 'kern'))
            : [];
    }

    /** @return list<int> */
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

    /** @return list<int> */
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
}
