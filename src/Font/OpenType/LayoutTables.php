<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use Php2Pdf\Exception\ParseException;

use function array_key_exists;
use function array_keys;
use function count;
use function ksort;
use function max;
use function usort;

/**
 * The parts of a GSUB or GPOS table that applying lookups reads, parsed on first use and kept:
 * the lookups of each feature in a script's default language system (the default script's, then
 * Latin's, when the font has no entry for the script), the required feature, each lookup's flag
 * and subtables through extensions, coverage tables, class definitions and ligature sets.
 *
 * @internal
 */
final class LayoutTables
{
    /** @var array<string, array<string, list<int>>> script => feature tag => lookup indices; '' holds the required feature */
    private array $features = [];

    /** @var array<int, list<array{0: int, 1: int}>> lookup index => (type, subtable offset) */
    private array $subtables = [];

    /** @var array<int, GlyphFilter> lookup index => the glyphs it passes over */
    private array $filters = [];

    /** @var array<int, array<int, list<array{0: int, 1: int}>>> lookup index => glyph a match can start at => the subtables that can */
    private array $starts = [];

    /** @var array<int, array<int, int>> coverage offset => glyph => index */
    private array $coverages = [];

    /** @var array<int, array<int, int>> class definition offset => glyph => class */
    private array $classDefs = [];

    /** @var array<int, array<int, list<array{0: list<int>, 1: int}>>> ligature subtable offset => first glyph => ligatures, longest first */
    private array $ligatureSets = [];

    private function __construct(
        public readonly BinaryReader $reader,
        public readonly ?Gdef $gdef,
        public readonly int $contextType,
        public readonly int $chainType,
        private readonly int $extensionType,
    ) {}

    public static function gpos(BinaryReader $gpos, ?Gdef $gdef): self
    {
        return new self($gpos, $gdef, contextType: 7, chainType: 8, extensionType: 9);
    }

    public static function gsub(BinaryReader $gsub, ?Gdef $gdef): self
    {
        return new self($gsub, $gdef, contextType: 5, chainType: 6, extensionType: 7);
    }

    /**
     * @return array<int, int> glyph => class
     *
     * @throws ParseException
     */
    public static function readClassDef(BinaryReader $r, int $offset): array
    {
        $map = [];
        if (1 === $r->u16($offset)) {
            $start = $r->u16($offset + 2);
            foreach ($r->u16List($offset + 6, $r->u16($offset + 4)) as $k => $class) {
                $map[$start + $k] = $class;
            }
        }
        if (2 === $r->u16($offset)) {
            $count = $r->u16($offset + 2);
            for ($k = 0; $k < $count; ++$k) {
                $record = $offset + 4 + (6 * $k);
                $class  = $r->u16($record + 4);
                for ($glyph = $r->u16($record); $glyph <= $r->u16($record + 2); ++$glyph) {
                    $map[$glyph] = $class;
                }
            }
        }

        return $map;
    }

    /**
     * @return array<int, int> glyph => coverage index
     *
     * @throws ParseException
     */
    public static function readCoverage(BinaryReader $r, int $offset): array
    {
        $count = $r->u16($offset + 2);
        $map   = [];
        if (1 === $r->u16($offset)) {
            foreach ($r->u16List($offset + 4, $count) as $index => $glyph) {
                $map[$glyph] = $index;
            }
        }
        if (2 === $r->u16($offset)) {
            for ($k = 0; $k < $count; ++$k) {
                $record = $offset + 4 + (6 * $k);
                $base   = $r->u16($record + 4);
                $start  = $r->u16($record);
                for ($glyph = $start; $glyph <= $r->u16($record + 2); ++$glyph) {
                    $map[$glyph] = $base + ($glyph - $start);
                }
            }
        }

        return $map;
    }

    /**
     * @return array<int, int> glyph => class
     *
     * @throws ParseException
     */
    public function classDef(int $offset): array
    {
        return $this->classDefs[$offset] ??= self::readClassDef($this->reader, $offset);
    }

    /**
     * @return array<int, int> glyph => coverage index
     *
     * @throws ParseException
     */
    public function coverage(int $offset): array
    {
        return $this->coverages[$offset] ??= self::readCoverage($this->reader, $offset);
    }

    /**
     * The glyphs a lookup passes over, from its flag and mark filtering set.
     *
     * @throws ParseException
     */
    public function filter(int $lookup): GlyphFilter
    {
        if (array_key_exists($lookup, $this->filters)) {
            return $this->filters[$lookup];
        }
        $r          = $this->reader;
        $lookupList = $r->u16(8);
        $flag       = 0;
        $set        = 0;
        if ($lookup < $r->u16($lookupList)) {
            $offset = $lookupList + $r->u16($lookupList + 2 + (2 * $lookup));
            $flag   = $r->u16($offset + 2);
            if (0 !== ($flag & GlyphFilter::USE_MARK_FILTERING_SET)) {
                $set = $r->u16($offset + 6 + (2 * $r->u16($offset + 4)));
            }
        }

        return $this->filters[$lookup] = new GlyphFilter($this->gdef, $flag, $set);
    }

    /**
     * Whether the table has an entry for a script.
     *
     * @throws ParseException
     */
    public function hasScript(string $tag): bool
    {
        $r          = $this->reader;
        $scriptList = $r->u16(4);
        $count      = $r->u16($scriptList);
        for ($k = 0; $k < $count; ++$k) {
            if ($r->tag($scriptList + 2 + (6 * $k)) === $tag) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, list<array{0: list<int>, 1: int}>>
     *
     * @throws ParseException
     */
    public function ligatureSet(int $offset): array
    {
        if (array_key_exists($offset, $this->ligatureSets)) {
            return $this->ligatureSets[$offset];
        }
        $r   = $this->reader;
        $map = [];
        if (1 === $r->u16($offset)) {
            foreach ($this->coverage($offset + $r->u16($offset + 2)) as $first => $index) {
                $set = $offset + $r->u16($offset + 6 + (2 * $index));
                foreach ($r->u16List($set + 2, $r->u16($set)) as $ligatureOffset) {
                    $ligature      = $set + $ligatureOffset;
                    $map[$first][] = [
                        $r->u16List($ligature + 4, max(0, $r->u16($ligature + 2) - 1)),
                        $r->u16($ligature),
                    ];
                }
            }
        }
        foreach ($map as $first => $ligatures) {
            usort(
                $ligatures,
                /**
                 * @param array{0: list<int>, 1: int} $a
                 * @param array{0: list<int>, 1: int} $b
                 */
                static fn(array $a, array $b): int => count($b[0]) <=> count($a[0]),
            );
            $map[$first] = $ligatures;
        }

        return $this->ligatureSets[$offset] = $map;
    }

    /**
     * @return list<array{0: int, 1: int}> sequence index and lookup index
     *
     * @throws ParseException
     */
    public function lookupRecords(int $at, int $count): array
    {
        $r       = $this->reader;
        $records = [];
        for ($k = 0; $k < $count; ++$k) {
            $records[] = [$r->u16($at + (4 * $k)), $r->u16($at + 2 + (4 * $k))];
        }

        return $records;
    }

    /**
     * Lookup indices of the features in a script, with the script's required feature, in lookup
     * list order.
     *
     * @param list<string> $features
     * @param string|null  $language an OpenType language system tag ("TRK "); the script's
     *                               default when the font has none for it
     *
     * @return list<int>
     *
     * @throws ParseException
     */
    public function lookupsFor(array $features, string $script = 'latn', ?string $language = null): array
    {
        $key = "{$script}|{$language}";
        if (! array_key_exists($key, $this->features)) {
            $this->features[$key] = $this->readFeatures($script, $language);
        }
        $byFeature = $this->features[$key];
        $indices   = [];
        foreach (['', ...$features] as $feature) {
            foreach ($byFeature[$feature] ?? [] as $index) {
                $indices[$index] = true;
            }
        }
        ksort($indices);

        return array_keys($indices);
    }

    /**
     * The glyphs a lookup's subtables can match at, as keys, each with the subtables that can: by
     * their coverage (for mark attachment, the mark coverage), or for context and chained context
     * by coverage (format 3) the first input position's coverage.
     *
     * @return array<int, list<array{0: int, 1: int}>>
     *
     * @throws ParseException
     */
    public function startGlyphs(int $lookup): array
    {
        if (array_key_exists($lookup, $this->starts)) {
            return $this->starts[$lookup];
        }
        $r      = $this->reader;
        $starts = [];
        foreach ($this->subtables($lookup) as $subtable) {
            [$type, $offset] = $subtable;
            $coverage = $offset + $r->u16($offset + 2);
            if ($this->contextType === $type && 3 === $r->u16($offset)) {
                $coverage = $offset + $r->u16($offset + 6);
            }
            if ($this->chainType === $type && 3 === $r->u16($offset)) {
                $backtrack = $r->u16($offset + 2);
                $input     = $offset + 4 + (2 * $backtrack);
                if (0 === $r->u16($input)) {
                    continue;
                }
                $coverage = $offset + $r->u16($input + 2);
            }
            foreach ($this->coverage($coverage) as $glyph => $_) {
                $starts[$glyph][] = $subtable;
            }
        }

        return $this->starts[$lookup] = $starts;
    }

    /**
     * @return list<array{0: int, 1: int}>
     *
     * @throws ParseException
     */
    public function subtables(int $lookup): array
    {
        if (array_key_exists($lookup, $this->subtables)) {
            return $this->subtables[$lookup];
        }
        $r          = $this->reader;
        $lookupList = $r->u16(8);
        $subtables  = [];
        if ($lookup < $r->u16($lookupList)) {
            $offset = $lookupList + $r->u16($lookupList + 2 + (2 * $lookup));
            $type   = $r->u16($offset);
            foreach ($r->u16List($offset + 6, $r->u16($offset + 4)) as $subtableOffset) {
                $subtable = $offset + $subtableOffset;
                $realType = $type;
                if ($this->extensionType === $type) {
                    $realType = $r->u16($subtable + 2);
                    $subtable += $r->u32($subtable + 4);
                }
                $subtables[] = [$realType, $subtable];
            }
        }

        return $this->subtables[$lookup] = $subtables;
    }

    /**
     * The subtables of a lookup that can match at a glyph, in order.
     *
     * @return list<array{0: int, 1: int}>
     *
     * @throws ParseException
     */
    public function subtablesAt(int $lookup, int $glyph): array
    {
        return $this->startGlyphs($lookup)[$glyph] ?? [];
    }

    /**
     * Where a script's language system for a language starts: the language's own when the font
     * has one, otherwise the script's default; null when there is neither.
     *
     * @throws ParseException
     */
    private function languageSystem(int $script, ?string $language): ?int
    {
        $r = $this->reader;
        if (null !== $language) {
            for ($k = 0, $count = $r->u16($script + 2); $k < $count; ++$k) {
                $record = $script + 4 + (6 * $k);
                if ($language === $r->tag($record)) {
                    return $script + $r->u16($record + 4);
                }
            }
        }
        $default = $r->u16($script);

        return 0 === $default ? null : $script + $default;
    }

    /**
     * The features of a script's default language system, by tag, with the required feature
     * under ''.
     *
     * @return array<string, list<int>>
     *
     * @throws ParseException
     */
    private function readFeatures(string $tag, ?string $language): array
    {
        $r          = $this->reader;
        $scriptList = $r->u16(4);
        $scripts    = [];
        $count      = $r->u16($scriptList);
        for ($k = 0; $k < $count; ++$k) {
            $record                    = $scriptList + 2 + (6 * $k);
            $scripts[$r->tag($record)] = $scriptList + $r->u16($record + 4);
        }
        $script = $scripts[$tag] ?? $scripts['DFLT'] ?? $scripts['dflt'] ?? $scripts['latn'] ?? null;
        $lang   = null === $script ? null : $this->languageSystem($script, $language);
        if (null === $lang) {
            return [];
        }
        $featureList = $r->u16(6);
        $features    = [];
        $required    = $r->u16($lang + 2);
        $indices     = $r->u16List($lang + 6, $r->u16($lang + 4));
        if (0xFFFF !== $required) {
            $indices[] = $required;
        }
        foreach ($indices as $position => $featureIndex) {
            $record  = $featureList + 2 + (6 * $featureIndex);
            $feature = $featureList + $r->u16($record + 4);
            $name    = 0xFFFF !== $required && $position === (count($indices) - 1) ? '' : $r->tag($record);
            foreach ($r->u16List($feature + 4, $r->u16($feature + 2)) as $lookup) {
                $features[$name][] = $lookup;
            }
        }

        return $features;
    }
}
