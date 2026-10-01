<?php

declare(strict_types=1);

namespace PhpPdf\Font\OpenType;

use function array_column;
use function array_slice;
use function count;
use function in_array;
use function ksort;
use function usort;

/**
 * The standard ligatures of a font: ligature substitution lookups (type 4, directly or through an
 * extension, type 7) of the GSUB "liga" feature for the Latin script, or the default script when
 * the font has no Latin entry. Each lookup is applied in turn over a glyph sequence, longest
 * ligature first at each position.
 *
 * @internal
 */
final class GsubLigatures
{
    private const string FEATURE = 'liga';

    /** @var list<array<int, list<array{0: list<int>, 1: int}>>> per lookup: first glyph => (other components, ligature), longest first */
    private array $lookups = [];

    public function __construct(
        private readonly BinaryReader $gsub,
    ) {
        foreach ($this->featureLookups() as $lookup) {
            $map = $this->ligatureLookup($lookup);
            if ([] !== $map) {
                $this->lookups[] = $map;
            }
        }
    }

    /**
     * Substitutes ligatures in a glyph sequence. Each entry is a glyph id with the code points it
     * stands for; a ligature takes the code points of all its components.
     *
     * @param list<array{0: int, 1: list<int>}> $glyphs
     *
     * @return list<array{0: int, 1: list<int>}>
     */
    public function apply(array $glyphs): array
    {
        foreach ($this->lookups as $lookup) {
            $out  = [];
            $skip = 0;
            foreach ($glyphs as $i => $glyph) {
                if ($skip > 0) {
                    --$skip;
                    continue;
                }
                $match = $this->match($lookup[$glyph[0]] ?? [], $glyphs, $i);
                if (null === $match) {
                    $out[] = $glyph;
                    continue;
                }
                [$ligature, $length] = $match;
                $codePoints = [];
                foreach (array_slice($glyphs, $i, $length) as [, $components]) {
                    foreach ($components as $codePoint) {
                        $codePoints[] = $codePoint;
                    }
                }
                $out[] = [$ligature, $codePoints];
                $skip  = $length - 1;
            }
            $glyphs = $out;
        }

        return $glyphs;
    }

    public function hasLigatures(): bool
    {
        return [] !== $this->lookups;
    }

    /** @return array<int, int> glyph => coverage index */
    private function coverage(int $offset): array
    {
        $r      = $this->gsub;
        $format = $r->u16($offset);
        $count  = $r->u16($offset + 2);
        $map    = [];
        if (1 === $format) {
            foreach ($r->u16List($offset + 4, $count) as $index => $glyph) {
                $map[$glyph] = $index;
            }

            return $map;
        }
        for ($i = 0; $i < $count; ++$i) {
            $record = $offset + 4 + (6 * $i);
            $start  = $r->u16($record);
            $end    = $r->u16($record + 2);
            $base   = $r->u16($record + 4);
            for ($glyph = $start; $glyph <= $end; ++$glyph) {
                $map[$glyph] = $base + ($glyph - $start);
            }
        }

        return $map;
    }

    /**
     * Lookup offsets of the "liga" feature in the Latin script's default language system (or the
     * default script's), in lookup list order.
     *
     * @return list<int>
     */
    private function featureLookups(): array
    {
        $r        = $this->gsub;
        $features = $this->languageFeatures();
        if ([] === $features) {
            return [];
        }
        $featureList = $r->u16(6);
        $lookupList  = $r->u16(8);
        $indices     = [];
        foreach ($features as $featureIndex) {
            $record = $featureList + 2 + (6 * $featureIndex);
            if (self::FEATURE !== $r->tag($record)) {
                continue;
            }
            $feature = $featureList + $r->u16($record + 4);
            foreach ($r->u16List($feature + 4, $r->u16($feature + 2)) as $index) {
                $indices[$index] = true;
            }
        }
        ksort($indices);
        $offsets = [];
        foreach ($indices as $index => $_) {
            $offsets[] = $lookupList + $r->u16($lookupList + 2 + (2 * $index));
        }

        return $offsets;
    }

    /**
     * Feature indices of the default language system of the "latn" script, else "DFLT".
     *
     * @return list<int>
     */
    private function languageFeatures(): array
    {
        $r          = $this->gsub;
        $scriptList = $r->u16(4);
        $count      = $r->u16($scriptList);
        $scripts    = [];
        for ($i = 0; $i < $count; ++$i) {
            $record                    = $scriptList + 2 + (6 * $i);
            $scripts[$r->tag($record)] = $scriptList + $r->u16($record + 4);
        }
        $script = $scripts['latn'] ?? $scripts['DFLT'] ?? null;
        if (null === $script) {
            return [];
        }
        $defaultLang = $r->u16($script);
        if (0 === $defaultLang) {
            return [];
        }
        $lang = $script + $defaultLang;

        return $r->u16List($lang + 6, $r->u16($lang + 4));
    }

    /**
     * The ligatures of one lookup, by first glyph.
     *
     * @return array<int, list<array{0: list<int>, 1: int}>>
     */
    private function ligatureLookup(int $lookup): array
    {
        $r    = $this->gsub;
        $type = $r->u16($lookup);
        $map  = [];
        foreach ($r->u16List($lookup + 6, $r->u16($lookup + 4)) as $offset) {
            $subtable = $lookup + $offset;
            $realType = $type;
            if (7 === $type) {
                $realType = $r->u16($subtable + 2);
                $subtable += $r->u32($subtable + 4);
            }
            if (4 !== $realType || 1 !== $r->u16($subtable)) {
                continue;
            }
            $this->ligatureSubst($subtable, $map);
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

        return $map;
    }

    /**
     * Adds the ligatures of a format 1 ligature substitution subtable.
     *
     * @param array<int, list<array{0: list<int>, 1: int}>> $map
     */
    private function ligatureSubst(int $subtable, array &$map): void
    {
        $r = $this->gsub;
        foreach ($this->coverage($subtable + $r->u16($subtable + 2)) as $first => $index) {
            $set = $subtable + $r->u16($subtable + 6 + (2 * $index));
            foreach ($r->u16List($set + 2, $r->u16($set)) as $ligatureOffset) {
                $ligature   = $set + $ligatureOffset;
                $components = $r->u16List($ligature + 4, $r->u16($ligature + 2) - 1);
                $entry      = [$components, $r->u16($ligature)];
                if (! in_array($entry, $map[$first] ?? [], strict: true)) {
                    $map[$first][] = $entry;
                }
            }
        }
    }

    /**
     * The ligature at $i, longest first: its glyph and how many glyphs it replaces, or null.
     *
     * @param list<array{0: list<int>, 1: int}> $candidates
     * @param list<array{0: int, 1: list<int>}> $glyphs
     *
     * @return array{0: int, 1: int}|null
     */
    private function match(array $candidates, array $glyphs, int $i): ?array
    {
        foreach ($candidates as [$components, $ligature]) {
            $next = array_column(array_slice($glyphs, $i + 1, count($components)), column_key: 0);
            if ($next !== $components) {
                continue;
            }

            return [$ligature, count($components) + 1];
        }

        return null;
    }
}
