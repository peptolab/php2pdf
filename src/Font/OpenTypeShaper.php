<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use Closure;
use IntlChar;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\OpenType\LanguageSystems;
use Php2Pdf\Font\OpenType\OpenTypeFont;

use function array_diff;
use function array_flip;
use function array_key_exists;
use function array_keys;
use function array_reverse;
use function array_slice;
use function array_values;
use function class_exists;
use function count;
use function in_array;
use function ksort;
use function mb_check_encoding;
use function mb_chr;
use function mb_convert_encoding;
use function mb_ord;
use function mb_str_split;
use function preg_match;
use function strlen;
use function unpack;

/**
 * Shapes text with an OpenType font as HarfBuzz does: normalises it for the font, splits it into
 * script runs, mirrors characters set right to left, substitutes glyphs from GSUB (in the Arabic
 * shaper's stages and joining forms for the joining scripts, with presentation forms when an
 * Arabic font has no joining features), and positions them from GPOS, or from the kern table.
 *
 * @internal
 */
final readonly class OpenTypeShaper
{
    /** Joining scripts HarfBuzz shapes as Arabic when the font has features for them. */
    private const array JOINING_SCRIPTS = [
        'mong',
        'mand',
        'mani',
        'phlp',
        'adlm',
        'rohg',
        'sogd',
        'chrs',
        'ougr',
        'nko ',
    ];

    /** The features the Arabic shaper applies in stages of their own, before the rest. */
    private const array STAGED = ['rtla', 'rtlm', 'ccmp', 'locl', 'rlig', 'rclt', 'calt'];

    private float $scale;

    /**
     * @param Closure(int): int          $advance a glyph's advance in thousandths of an em, as the
     *                                            font's widths carry it
     * @param ExternalShaperInterface|null $external shapes the scripts the engine does not
     */
    public function __construct(
        private OpenTypeFont $program,
        private Closure $advance,
        private ?ExternalShaperInterface $external = null,
    ) {
        $this->scale = 1000.0 / $program->unitsPerEm;
    }

    /**
     * The text's code points: valid text decodes in one call; ill-formed text is read character by
     * character, dropping what does not decode.
     *
     * @return list<int>
     */
    private static function decoded(string $text): array
    {
        if (mb_check_encoding($text, encoding: 'UTF-8')) {
            /** @var array<int, int> $units */
            $units = unpack('N*', (string) mb_convert_encoding($text, to_encoding: 'UTF-32BE', from_encoding: 'UTF-8'));

            return array_values($units);
        }
        $codePoints = [];
        foreach (mb_str_split($text, length: 1, encoding: 'UTF-8') as $char) {
            $codePoint = mb_ord($char, encoding: 'UTF-8');
            if (false === $codePoint) {
                continue;
            }
            $codePoints[] = $codePoint;
        }

        return $codePoints;
    }

    /** Whether a character at U+00AD or above (the first) is default-ignorable. */
    private static function ignorable(int $codePoint): bool
    {
        return IntlChar::hasBinaryProperty($codePoint, IntlChar::PROPERTY_DEFAULT_IGNORABLE_CODE_POINT);
    }

    /**
     * The features of a group that are among those asked for.
     *
     * @param list<string> $features
     * @param list<string> $group
     *
     * @return list<string>
     */
    private static function present(array $features, array $group): array
    {
        $out = [];
        foreach ($group as $feature) {
            if (! in_array($feature, $features, strict: true)) {
                continue;
            }

            $out[] = $feature;
        }

        return $out;
    }

    /** @param list<int> $codePoints */
    private static function text(array $codePoints): string
    {
        $text = '';
        foreach ($codePoints as $codePoint) {
            $text .= (string) mb_chr($codePoint, encoding: 'UTF-8');
        }

        return $text;
    }

    /**
     * The glyphs for text, in visual order: right to left when the options say so.
     *
     * @param list<string> $features the GSUB features, with the direction's
     *
     * @return list<Glyph>
     *
     * @throws ParseException
     */
    public function shape(string $text, TextOptions $options, array $features): array
    {
        $rtl = TextDirection::RightToLeft === $options->direction;
        [$codePoints, $sources] = $this->codePoints($text);
        // One entry per distinct code point, shared by its occurrences: arrays are copied on
        // write, so a change to one occurrence's entry leaves the others as they are.
        $sequence = [];
        $entries  = [];
        foreach ($codePoints as $codePoint) {
            $entry = $entries[$codePoint] ?? null;
            if (null === $entry) {
                $shown               = $rtl ? $this->mirrored($codePoint) : $codePoint;
                $entry               = [$this->program->glyphId($shown), [$shown]];
                $entries[$codePoint] = $entry;
            }
            $sequence[] = $entry;
        }
        unset($entries);
        $gsub     = $this->program->gsub();
        $language = LanguageSystems::forLanguage($options->language);
        $glyphs   = [];
        $at       = 0;
        $cluster  = 0;
        foreach (ScriptRuns::of($codePoints) as [$script, $start, $length]) {
            $runCodePoints = array_slice($codePoints, $start, $length);
            if (null !== $this->external && null !== ComplexScripts::find(self::text($runCodePoints))) {
                $placed = $this->externallyShaped($runCodePoints, $options, $script);
                $this->glyphs($placed, $sources, $glyphs, $at, $cluster);
                continue;
            }
            $run = array_slice($sequence, $start, $length);
            if ($start + $length >= count($sequence)) {
                // The last run: the whole sequence is no longer needed while it is shaped.
                unset($sequence, $codePoints);
            }
            $run = $this->joins($script)
                ? $this->joined($run, $runCodePoints, $features, $script, $language)
                : $gsub?->apply($run, $features, $script, $language) ?? $run;
            $this->positioned($run, $options, $features, $script, $sources, $glyphs, $at, $cluster);
        }

        return $rtl ? array_reverse($glyphs) : $glyphs;
    }

    /**
     * The text's code points, normalised for the font when it has combining marks or characters
     * the font lacks, each with the index in the text of the character it comes from: null when
     * each comes from the character at its own index.
     *
     * @return array{0: list<int>, 1: list<int>|null}
     */
    private function codePoints(string $text): array
    {
        $codePoints = self::decoded($text);
        $missing    = false;
        // Each character once: text repeats a few dozen characters many times over.
        foreach (array_flip($codePoints) as $codePoint => $_) {
            if (0 !== $this->program->glyphId($codePoint)) {
                continue;
            }
            $missing = true;
            break;
        }
        if (! $missing && 1 !== preg_match('/\p{M}/u', $text)) {
            return [$codePoints, null];
        }

        return Normaliser::normalise(
            $codePoints,
            fn(int $codePoint): bool => 0 !== $this->program->glyphId($codePoint),
        );
    }

    /**
     * A run shaped by the external shaper, in logical order: each glyph positioned as the shaper
     * places it, the first glyph of each cluster standing for the cluster's characters.
     *
     * @param list<int> $codePoints
     *
     * @return list<array{0: int, 1: int, 2: int, 3: float, 4: list<int>, 5: float, 6: float, 7: int}>
     */
    private function externallyShaped(array $codePoints, TextOptions $options, string $script): array
    {
        $text   = '';
        $starts = [];
        foreach ($codePoints as $k => $codePoint) {
            $starts[strlen($text)] = $k;
            $text                  .= (string) mb_chr($codePoint, encoding: 'UTF-8');
        }
        $features = [
            ...$options->features,
            ...($options->useLigatures() ? [] : ['-liga', '-clig']),
            ...($options->kerning ? [] : ['-kern']),
        ];
        $shaped = $this->external?->shape(
            $this->program,
            $text,
            ScriptRuns::iso($script),
            $options->direction,
            $features,
            $options->language,
        ) ?? [];
        if (TextDirection::RightToLeft === $options->direction) {
            $shaped = array_reverse($shaped);
        }
        $clusters = [];
        foreach ($shaped as [, $cluster]) {
            $clusters[$cluster] = true;
        }
        ksort($clusters);
        $ends  = [];
        $prior = null;
        foreach (array_keys($clusters) as $cluster) {
            if (null !== $prior) {
                $ends[$prior] = $starts[$cluster] ?? count($codePoints);
            }
            $prior = $cluster;
        }
        $seen   = [];
        $glyphs = [];
        foreach ($shaped as [$id, $cluster, $xAdvance, , $xOffset, $yOffset]) {
            $first = $starts[$cluster] ?? 0;
            $chars = array_key_exists($cluster, $seen)
                ? []
                : array_slice($codePoints, $first, ($ends[$cluster] ?? count($codePoints)) - $first);
            $seen[$cluster] = true;
            $glyphs[]       = $this->placed($id, $chars, $xAdvance, $xOffset, $yOffset);
        }

        return $glyphs;
    }

    /**
     * Adds the glyphs for placed glyphs, in logical order, each with the index in the text of the
     * character its cluster starts at: a glyph that stands for no character of its own (the
     * second of a multiple substitution) joins the cluster before it. Default-ignorable characters
     * (joiners, direction marks, variation selectors) are shown as the space glyph with no width or
     * offset, as HarfBuzz hides them; they keep their text.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: float, 4: list<int>, 5: float, 6: float, 7: int}> $placed
     * @param list<int>|null                                                                       $sources the index in
     *     the text of each normalised code point, null when it is its own index
     * @param list<Glyph>                                                                          $glyphs  the glyphs so far
     * @param int                                                                                  $at      how many
     *     normalised code points the glyphs so far stand for
     * @param int                                                                                  $cluster the last glyph's cluster
     */
    private function glyphs(array $placed, ?array $sources, array &$glyphs, int &$at, int &$cluster): void
    {
        $hidden = $this->hidden();
        foreach ($placed as [$id, $codePoint, $advance, $kerning, $components, $xOffset, $yOffset, $length]) {
            if ($length > 0) {
                $cluster = null === $sources ? $at : $sources[$at] ?? $cluster;
            }
            $at       += $length;
            $glyphs[] = null !== $hidden && $codePoint >= 0xAD && [] === $components && self::ignorable($codePoint)
                ? new Glyph($hidden[0], $codePoint, $hidden[1], -$hidden[1], cluster: $cluster)
                : new Glyph($id, $codePoint, $advance, $kerning, $components, $xOffset, $yOffset, $cluster);
        }
    }

    /**
     * The glyph default-ignorable characters are shown as, the space (its advance taken back), and
     * its advance; null when the font has no space or ICU cannot tell those characters.
     *
     * @return array{0: int, 1: int}|null
     */
    private function hidden(): ?array
    {
        $space = $this->program->glyphId(0x20);

        return 0 !== $space && class_exists(IntlChar::class) ? [$space, ($this->advance)($space)] : null;
    }

    /**
     * A run of a joining script, substituted as HarfBuzz's Arabic shaper does: each letter's
     * joining form, then the features in its stages, with presentation forms for an Arabic font
     * that has no joining features.
     *
     * @param list<array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}> $run
     * @param list<int>                                                        $codePoints
     * @param list<string>                                                     $features
     *
     * @return list<array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}>
     *
     * @throws ParseException
     */
    private function joined(
        array $run,
        array $codePoints,
        array $features,
        string $script,
        ?string $language,
    ): array {
        foreach (ArabicJoining::forms($codePoints) as $k => $form) {
            $run[$k][4] = $form;
        }
        $gsub = $this->program->gsub();
        if ('arab' === $script && ! ($gsub?->has(['init', 'medi', 'fina'], $script) ?? false)) {
            $run = ArabicFallback::apply($run, $codePoints, $this->program->glyphId(...));
            if (! ($gsub?->has(['rlig'], $script) ?? false)) {
                $run = ArabicFallback::lamAlef($run, $codePoints, $this->program->glyphId(...));
            }
        }
        if (null === $gsub) {
            return $run;
        }
        $stages = [self::present($features, ['rtla', 'rtlm']), self::present($features, ['ccmp', 'locl'])];
        foreach (ArabicJoining::FEATURES as $form) {
            $stages[] = [$form];
        }
        $stages[] = self::present($features, ['rlig']);
        $stages[] = self::present($features, ['rclt', 'calt']);
        $stages[] = ['mset', ...array_values(array_diff($features, self::STAGED))];

        return $gsub->applyStages($run, $stages, $script, $language);
    }

    /**
     * Whether text in a script is shaped as Arabic is, with joining forms: Arabic and Syriac
     * always, the other joining scripts when the font has features for them, as HarfBuzz decides.
     */
    private function joins(string $script): bool
    {
        if ('arab' === $script || 'syrc' === $script) {
            return true;
        }

        return (
            in_array($script, self::JOINING_SCRIPTS, strict: true)
                && ($this->program->gsub()?->hasScript($script) ?? false)
        );
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

        return 0 === $this->program->glyphId($mirror) ? $codePoint : $mirror;
    }

    /**
     * A glyph placed as a shaper positioned it, in font units: its advance and offsets become
     * kerning and offsets in thousandths of an em. A glyph with no advance takes none (a mark
     * over the glyph before it). The first of its code points is the character it stands for.
     * It is Glyph's fields up to the cluster (id, code point, advance, kerning, components and
     * offsets), then how many characters it stands for; glyphs() makes the Glyph.
     *
     * @param list<int> $codePoints
     *
     * @return array{0: int, 1: int, 2: int, 3: float, 4: list<int>, 5: float, 6: float, 7: int}
     */
    private function placed(int $id, array $codePoints, int $xAdvance, int $xOffset, int $yOffset): array
    {
        $advance = ($this->advance)($id);
        $natural = $this->program->advance($id);
        $kerning = 0 === $xAdvance && 0 !== $natural ? -$advance : ($xAdvance - $natural) * $this->scale;
        $length  = count($codePoints);

        return [
            $id,
            $codePoints[0] ?? -1,
            $advance,
            (float) $kerning,
            $length > 1 ? $codePoints : [],
            $xOffset * $this->scale,
            $yOffset * $this->scale,
            $length,
        ];
    }

    /**
     * Adds the glyphs for a substituted sequence, positioned by the font's GPOS (kerning when it
     * has a kern feature, mark and cursive attachment, and the requested features), else kerned
     * from its kern table, as placed() makes them and glyphs() adds them.
     *
     * @param list<array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}> $sequence
     * @param list<string>                                                    $features
     * @param list<int>|null                                                  $sources  as glyphs() takes them
     * @param list<Glyph>                                                     $glyphs
     *
     * @throws ParseException
     */
    private function positioned(
        array $sequence,
        TextOptions $options,
        array $features,
        string $script,
        ?array $sources,
        array &$glyphs,
        int &$at,
        int &$cluster,
    ): void {
        $language     = LanguageSystems::forLanguage($options->language);
        $gpos         = $this->program->gpos();
        $gposFeatures = [
            ...($options->kerning ? ['kern'] : []),
            'mark',
            'mkmk',
            'curs',
            'dist',
            'abvm',
            'blwm',
            ...$features,
        ];
        $positions = null;
        $advances  = [];
        if (null !== $gpos && $gpos->has($gposFeatures, $script, $language)) {
            foreach ($sequence as [$id]) {
                $advances[] = $this->program->advance($id);
            }
            $positions = $gpos->position(
                $sequence,
                $advances,
                $gposFeatures,
                $script,
                $options->direction,
                $language,
            );
        }
        $legacyKern =
            $options->kerning
            && (null === $positions || ! ($gpos?->has(['kern'], $script, $language) ?? false))
            && $this->program->hasKerning();
        // Each glyph as placed() and glyphs() make it, with the advances already read and each
        // glyph's width asked for once; a kern table pair adds to the first glyph's kerning.
        $hidden  = $this->hidden();
        $widths  = [];
        $advance = $this->advance;
        $scale   = $this->scale;
        foreach ($sequence as $k => [$id, $codePoints]) {
            $natural  = $advances[$k] ?? $this->program->advance($id);
            $position = null === $positions ? null : $positions[$k] ?? null;
            if (null === $position) {
                $xAdvance = $natural;
                $xOffset  = 0;
                $yOffset  = 0;
            } else {
                [$xAdvance, , $xOffset, $yOffset] = $position;
            }
            $width   = $widths[$id] ??= $advance($id);
            $kerning = (float) (0 === $xAdvance && 0 !== $natural ? -$width : ($xAdvance - $natural) * $scale);
            $next    = $legacyKern ? $sequence[$k + 1][0] ?? null : null;
            if (null !== $next) {
                $kern = $this->program->kerning($id, $next);
                if (0 !== $kern) {
                    $kerning += $kern * $scale;
                }
            }
            $codePoint = $codePoints[0] ?? -1;
            $length    = count($codePoints);
            if ($length > 0) {
                $cluster = null === $sources ? $at : $sources[$at] ?? $cluster;
            }
            $at       += $length;
            $glyphs[] = null !== $hidden && $codePoint >= 0xAD && $length < 2 && self::ignorable($codePoint)
                ? new Glyph($hidden[0], $codePoint, $hidden[1], -$hidden[1], cluster: $cluster)
                : new Glyph(
                    $id,
                    $codePoint,
                    $width,
                    $kerning,
                    $length > 1 ? $codePoints : [],
                    $xOffset * $scale,
                    $yOffset * $scale,
                    $cluster,
                );
        }
    }
}
