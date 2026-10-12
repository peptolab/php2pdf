<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

use Php2Pdf\Font\CoreFont;
use Php2Pdf\Font\Font;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\PdfFontInterface;
use Php2Pdf\Font\TextOptions;
use WeakMap;

use function array_slice;
use function chr;
use function count;
use function implode;
use function ord;
use function pack;
use function spl_object_id;
use function strlen;
use function substr;
use function unpack;

/**
 * The text-showing operation for a run joined from other runs, its pieces, one after another:
 * the operation TextOperators::operation() gives for the joined run, worked out from what each
 * piece shows, which is remembered for the piece. A line of text is joined from the runs of its
 * words and spaces, and the runs are shared by every item that shows the same word, so most
 * pieces are shown many times; their glyph codes and the adjustments among them are only joined
 * to the pieces' on either side.
 *
 * A document has one, so its pages and templates share what their words show, and nothing is kept
 * from one document to the next.
 *
 * @internal
 */
final class TextFragments
{
    /** How many runs are remembered, as seen or by what they show, before the memo starts over. */
    private const int FRAGMENTS = 16_384;

    /** A fragment flag: the font's ToUnicode map cannot give back the run's text. */
    private const int UNFAITHFUL = 1;

    /** A fragment flag: the run's last glyph leaves an adjustment for whatever follows it. */
    private const int PENDING = 2;

    /** A fragment flag: the run's glyphs are adjusted among themselves, so it has a middle. */
    private const int MIDDLE = 4;

    /** A fragment flag: a glyph of the run is moved by an offset, which is left to operation(). */
    private const int OFFSETS = 8;

    /** A flag for a run shown for the first time, which is left to operation() too. */
    private const int FRESH = 16;

    /** @var WeakMap<PdfFontInterface, array<int, string>>|null per font, each glyph's code escaped */
    private ?WeakMap $codes = null;

    /** How many runs $seen and $fragments hold together, give or take those of runs gone since. */
    private int $count = 0;

    /**
     * The runs shown once, by object id, started over with the fragments. An id left by a run
     * gone since only has the next run given it remembered the first time it is shown.
     *
     * @var array<int, true>
     */
    private array $seen = [];

    /**
     * Per font, what each run shows, kept while the run lives and the memo has room: for most
     * runs, those without flags, only their escaped glyph codes, and for the others their
     * fragments; see fragment() for what one holds.
     *
     * @var WeakMap<PdfFontInterface, array{0: WeakMap<GlyphRun, string>, 1: WeakMap<GlyphRun, string>}>|null
     */
    private ?WeakMap $fragments = null;

    /**
     * Adds a fragment to the operands so far, $parts and the open string $str, and sets $pending
     * to the adjustment it leaves; the fragment's flags, or FRESH for none.
     *
     * @param list<string> $parts
     */
    private static function join(string $fragment, array &$parts, string &$str, string &$pending): int
    {
        if ('' === $fragment) {
            return self::FRESH;
        }
        $flags = ord($fragment[0]);
        $at    = 1;
        if (0 !== ($flags & self::PENDING)) {
            $length  = ord($fragment[1]);
            $pending = substr($fragment, offset: 2, length: $length);
            $at      = 2 + $length;
        }
        if (0 === ($flags & self::MIDDLE)) {
            $str .= substr($fragment, $at);

            return $flags;
        }
        /** @var array{1: int, 2: int} $lengths */
        $lengths = unpack('N2', $fragment, $at);
        [1 => $head, 2 => $middle] = $lengths;
        $at      += 8;
        $parts[] = '(' . $str . substr($fragment, $at, $head) . ')';
        $parts[] = substr($fragment, $at + $head, $middle);
        $str     = substr($fragment, $at + $head + $middle);

        return $flags;
    }

    /**
     * The operation from its operands, those so far, the open string and the adjustment the last
     * glyph leaves, one string shown with Tj and anything more with TJ; and whether the font's
     * map gives back its text, from all the flags of its pieces. Null for glyphs moved by offsets
     * and for pieces shown for the first time.
     *
     * @param list<string> $parts
     *
     * @return array{0: string, 1: bool}|null
     */
    private static function operation(array $parts, string $str, string $pending, int $flags): ?array
    {
        if (0 !== ($flags & (self::OFFSETS | self::FRESH))) {
            return null;
        }
        if ('' !== $str) {
            $parts[] = "({$str})";
        }
        if ('' !== $pending) {
            $parts[] = $pending;
        }
        $operation = 1 === count($parts) ? "{$parts[0]} Tj" : '[' . implode(' ', $parts) . '] TJ';

        return [$operation, 0 === ($flags & self::UNFAITHFUL)];
    }

    /**
     * A fragment packed, from its flags, its head (null for a run that is all head), middle and
     * tail, and the adjustment its last glyph leaves.
     */
    private static function pack(int $flags, ?string $head, string $middle, string $tail, float $pending): string
    {
        $last = '';
        if (0.0 !== $pending) {
            $flags |= self::PENDING;
            $move  = TextOperators::adjustment($pending);
            $last  = chr(strlen($move)) . $move;
        }
        if (null === $head) {
            return chr($flags) . $last . $tail;
        }

        return (
            chr($flags | self::MIDDLE) . $last . pack('NN', strlen($head), strlen($middle)) . $head . $middle . $tail
        );
    }

    /**
     * The operation for a run joined from $pieces, and whether the font's ToUnicode map gives
     * back its text. $lastKernings gives, by position, the kerning of a piece's last glyph in the
     * joined run where it differs from the piece's own (a word space stretched by justification);
     * every other glyph is its piece's. The font is told a piece's glyphs are used, as
     * Font::markUsed() is, when what the piece shows is worked out.
     *
     * Null for what operation() shows instead: runs with tracking or word spacing, fonts whose
     * glyph codes are not glyph by glyph, glyphs moved by offsets, and runs with a piece shown for
     * the first time. A piece is remembered from the second time it is shown, so words shown only
     * once (all of a table's cells, often) cost little more than they did.
     *
     * @param list<GlyphRun>    $pieces
     * @param array<int, float> $lastKernings
     *
     * @return array{0: string, 1: bool}|null
     */
    public function show(
        array $pieces,
        array $lastKernings,
        PdfFontInterface $font,
        float $size,
        TextOptions $options,
    ): ?array {
        $known = $this->known($font, $size, $options);
        if (null === $known) {
            return null;
        }
        /** @var Font|CoreFont $font */
        [$plain, $flagged] = $known;
        $parts   = [];
        $str     = '';
        $pending = '';
        $all     = 0;
        foreach ($pieces as $index => $piece) {
            // A piece without glyphs leaves the adjustment before it to the glyph after it.
            if ([] === $piece->glyphs) {
                continue;
            }
            $codes = $plain[$piece] ?? null;
            if ('' !== $pending) {
                if ('' !== $str) {
                    $parts[] = "({$str})";
                    $str     = '';
                }
                $parts[] = $pending;
                $pending = '';
            }
            if (null !== $codes) {
                $str .= $codes;
            }
            if (null === $codes) {
                $fragment = $flagged[$piece] ?? $this->fragment($piece, $font, $known);
                $all      |= self::join($fragment, $parts, $str, $pending);
            }
            // A new piece: the run is left to operation(), and the pieces after it only noted.
            if (0 !== ($all & self::FRESH)) {
                $this->note($pieces, $index + 1, $known);
                break;
            }
            $kerning = $lastKernings[$index] ?? null;
            if (null !== $kerning) {
                $pending = 0.0 === $kerning ? '' : TextOperators::adjustment($kerning);
            }
        }

        return self::operation($parts, $str, $pending, $all);
    }

    /**
     * What a run shows, as TextOperators::operation() works it out from the run's glyphs alone
     * without tracking or word spacing; nothing (an empty string), with the run only noted in
     * $seen, the first time it is shown. The font is told the run's glyphs are used in the same
     * go. The operands are the run's head, the escaped codes before its first adjustment; its
     * middle, the adjustments and strings after that, ready to join; and its tail, the codes
     * after its last adjustment, which the strings of the runs after it continue. A run without
     * adjustments among its glyphs is all head.
     *
     * Packed in one string: a byte of flags; with PENDING, a byte of length and the adjustment
     * the last glyph leaves; then, with MIDDLE, the lengths of the head and the middle (32-bit,
     * big-endian) and the head, middle and tail, and otherwise the head. A run without flags is
     * remembered in $plain as its head alone, and any other in $flagged.
     *
     * @param array{0: WeakMap<GlyphRun, string>, 1: WeakMap<GlyphRun, string>} $known the font's runs
     */
    private function fragment(GlyphRun $run, Font|CoreFont $font, array $known): string
    {
        [$plain, $flagged] = $known;
        // Most words come back, but words each shown once would only fill the memo.
        $id = spl_object_id($run);
        ++$this->count;
        if (null === ($this->seen[$id] ?? null)) {
            $this->seen[$id] = true;

            return '';
        }
        $flags       = $font->markUsed($run) ? 0 : self::UNFAITHFUL;
        $this->codes ??= new WeakMap();
        /** @var array<int, string> $codes */
        $codes   = $this->codes[$font] ?? [];
        $head    = null;
        $middle  = '';
        $str     = '';
        $pending = 0.0;
        foreach ($run->glyphs as $glyph) {
            if (0.0 !== $glyph->xOffset || 0.0 !== $glyph->yOffset) {
                $flags |= self::OFFSETS;
                break;
            }
            // Every glyph code is at least one byte, so no string before an adjustment is empty.
            if (0.0 !== $pending) {
                $move   = TextOperators::adjustment($pending);
                $middle = null === $head ? $move : "{$middle} ({$str}) {$move}";
                $head   ??= $str;
                $str    = '';
            }
            $code         = $glyph->id;
            $codes[$code] ??= TextOperators::escape($font->encode([$code]));
            $str          .= $codes[$code];
            $pending      = $glyph->kerning;
        }
        $this->codes[$font] = $codes;
        $fragment           = 0 === ($flags & self::OFFSETS)
            ? self::pack($flags, $head, $middle, $str, $pending)
            : chr($flags);
        $isPlain    = "\0{$str}" === $fragment;
        $memo       = $isPlain ? $plain : $flagged;
        $memo[$run] = $isPlain ? $str : $fragment;

        return $fragment;
    }

    /**
     * The font's runs without flags and with, the memo started over first when it is full; null
     * when runs in the font with the options are not shown from their pieces: with tracking or
     * word spacing, or in a font whose glyph codes are not glyph by glyph.
     *
     * @return array{0: WeakMap<GlyphRun, string>, 1: WeakMap<GlyphRun, string>}|null
     */
    private function known(PdfFontInterface $font, float $size, TextOptions $options): ?array
    {
        $spaced = 0.0 !== $options->wordSpacing && $size > 0.0;
        if (0.0 !== $options->tracking || $spaced || (! $font instanceof Font && ! $font instanceof CoreFont)) {
            return null;
        }
        if ($this->count >= self::FRAGMENTS) {
            $this->fragments = null;
            $this->seen      = [];
            $this->count     = 0;
        }
        $this->fragments ??= new WeakMap();
        /** @var array{0: WeakMap<GlyphRun, string>, 1: WeakMap<GlyphRun, string>}|null $known */
        $known = $this->fragments[$font] ?? null;
        if (null === $known) {
            $known                  = [new WeakMap(), new WeakMap()];
            $this->fragments[$font] = $known;
        }

        return $known;
    }

    /**
     * Notes the pieces from $from on as seen, all but those already seen or remembered.
     *
     * @param list<GlyphRun>                                                   $pieces
     * @param array{0: WeakMap<GlyphRun, string>, 1: WeakMap<GlyphRun, string>} $known
     */
    private function note(array $pieces, int $from, array $known): void
    {
        [$plain, $flagged] = $known;
        foreach (array_slice($pieces, $from) as $piece) {
            $id = spl_object_id($piece);
            if (null !== ($this->seen[$id] ?? $plain[$piece] ?? $flagged[$piece] ?? null)) {
                continue;
            }
            $this->seen[$id] = true;
            ++$this->count;
        }
    }
}
