<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use Closure;
use IntlChar;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\OpenType\OpenTypeFont;

use function array_diff;
use function array_key_exists;
use function array_keys;
use function array_reverse;
use function array_slice;
use function array_values;
use function class_exists;
use function count;
use function in_array;
use function ksort;
use function mb_chr;
use function mb_ord;
use function mb_str_split;
use function preg_match;
use function strlen;

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
     * The glyphs, in logical order, with the index in the text of the character each one's
     * cluster starts at. A glyph that stands for no character of its own (the second of a
     * multiple substitution) joins the cluster before it.
     *
     * @param list<Glyph> $glyphs
     * @param list<int>   $sources the index in the text of each normalised code point
     *
     * @return list<Glyph>
     */
    private static function clustered(array $glyphs, array $sources): array
    {
        $at      = 0;
        $cluster = 0;
        $out     = [];
        foreach ($glyphs as $glyph) {
            $length  = $glyph->length();
            $cluster = $length > 0 ? $sources[$at] ?? $cluster : $cluster;
            $at      += $length;
            $out[]   = $glyph->withCluster($cluster);
        }

        return $out;
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
        $sequence = [];
        foreach ($codePoints as $codePoint) {
            $shown      = $rtl ? $this->mirrored($codePoint) : $codePoint;
            $sequence[] = [$this->program->glyphId($shown), [$shown]];
        }
        $gsub   = $this->program->gsub();
        $glyphs = [];
        foreach (ScriptRuns::of($codePoints) as [$script, $start, $length]) {
            $runCodePoints = array_slice($codePoints, $start, $length);
            if (null !== $this->external && null !== ComplexScripts::find(self::text($runCodePoints))) {
                foreach ($this->externallyShaped($runCodePoints, $options, $script) as $glyph) {
                    $glyphs[] = $glyph;
                }
                continue;
            }
            $run = array_slice($sequence, $start, $length);
            $run = $this->joins($script)
                ? $this->joined($run, array_slice($codePoints, $start, $length), $features, $script)
                : $gsub?->apply($run, $features, $script) ?? $run;
            foreach ($this->positioned($run, $options, $features, $script) as $glyph) {
                $glyphs[] = $glyph;
            }
        }

        $glyphs = $this->hideIgnorables(self::clustered($glyphs, $sources));

        return $rtl ? array_reverse($glyphs) : $glyphs;
    }

    /**
     * The text's code points, normalised for the font when it has combining marks or characters
     * the font lacks, each with the index in the text of the character it comes from.
     *
     * @return array{0: list<int>, 1: list<int>}
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
            return [$codePoints, array_keys($codePoints)];
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
     * @return list<Glyph>
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
            $advance        = ($this->advance)($id);
            $natural        = $this->program->advance($id);
            $kerning        = 0 === $xAdvance && 0 !== $natural ? -$advance : ($xAdvance - $natural) * $this->scale;
            $glyphs[]       = new Glyph(
                $id,
                $chars[0] ?? -1,
                $advance,
                $kerning,
                count($chars) > 1 ? $chars : [],
                $xOffset * $this->scale,
                $yOffset * $this->scale,
            );
        }

        return $glyphs;
    }

    /**
     * Default-ignorable characters (joiners, direction marks, variation selectors) shown as the
     * space glyph with no width or offset, as HarfBuzz hides them; they keep their text.
     *
     * @param list<Glyph> $glyphs
     *
     * @return list<Glyph>
     */
    private function hideIgnorables(array $glyphs): array
    {
        $space = $this->program->glyphId(0x20);
        if (0 === $space || ! class_exists(IntlChar::class)) {
            return $glyphs;
        }
        $width = ($this->advance)($space);
        foreach ($glyphs as $k => $glyph) {
            if (
                $glyph->codePoint < 0
                || [] !== $glyph->components
                || ! IntlChar::hasBinaryProperty($glyph->codePoint, IntlChar::PROPERTY_DEFAULT_IGNORABLE_CODE_POINT)
            ) {
                continue;
            }
            $glyphs[$k] = new Glyph($space, $glyph->codePoint, $width, -$width, cluster: $glyph->cluster);
        }

        return $glyphs;
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
    private function joined(array $run, array $codePoints, array $features, string $script): array
    {
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

        return $gsub->applyStages($run, $stages, $script);
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
     * Glyphs for a substituted sequence, positioned by the font's GPOS (kerning when it has a kern
     * feature, mark and cursive attachment, and the requested features), else kerned from its
     * kern table. A glyph's kerning carries what positioning adds to its advance; a mark
     * positioning gives no advance takes back its whole drawn width.
     *
     * @param list<array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}> $sequence
     * @param list<string>                                                    $features
     *
     * @return list<Glyph>
     *
     * @throws ParseException
     */
    private function positioned(array $sequence, TextOptions $options, array $features, string $script): array
    {
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
                $options->direction,
            );
        }
        $legacyKern =
            $options->kerning
            && (null === $positions || ! ($gpos?->has(['kern'], $script) ?? false))
            && $this->program->hasKerning();
        $glyphs = [];
        foreach ($sequence as $k => [$id, $codePoints]) {
            $advance = ($this->advance)($id);
            $glyph   = new Glyph($id, $codePoints[0] ?? -1, $advance, 0.0, count($codePoints) > 1 ? $codePoints : []);
            if (null !== $positions) {
                [$xAdvance, , $xOffset, $yOffset] = $positions[$k] ?? [0, 0, 0, 0];
                $natural = $this->program->advance($id);
                $kerning = 0 === $xAdvance && 0 !== $natural ? -$advance : ($xAdvance - $natural) * $this->scale;
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
}
