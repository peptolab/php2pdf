<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use Closure;
use IntlChar;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\OpenType\LanguageSystems;
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
        $gsub     = $this->program->gsub();
        $language = LanguageSystems::forLanguage($options->language);
        $placed   = [];
        foreach (ScriptRuns::of($codePoints) as [$script, $start, $length]) {
            $runCodePoints = array_slice($codePoints, $start, $length);
            $runPlaced     = null;
            if (null !== $this->external && null !== ComplexScripts::find(self::text($runCodePoints))) {
                $runPlaced = $this->externallyShaped($runCodePoints, $options, $script);
            }
            if (null === $runPlaced) {
                $run = array_slice($sequence, $start, $length);
                $run = $this->joins($script)
                    ? $this->joined($run, $runCodePoints, $features, $script, $language)
                    : $gsub?->apply($run, $features, $script, $language) ?? $run;
                $runPlaced = $this->positioned($run, $options, $features, $script);
            }
            if ([] === $placed) {
                $placed = $runPlaced;
                continue;
            }
            foreach ($runPlaced as $glyph) {
                $placed[] = $glyph;
            }
        }
        unset($sequence, $run, $runPlaced);
        $glyphs = $this->glyphs($placed, $sources);

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
        $codePoints = self::decoded($text);
        $missing    = false;
        foreach ($codePoints as $codePoint) {
            if (0 !== $this->program->glyphId($codePoint)) {
                continue;
            }
            $missing = true;
            break;
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
     * The glyphs for placed glyphs, in logical order, each with the index in the text of the
     * character its cluster starts at: a glyph that stands for no character of its own (the
     * second of a multiple substitution) joins the cluster before it. Default-ignorable characters
     * (joiners, direction marks, variation selectors) are shown as the space glyph with no width or
     * offset, as HarfBuzz hides them; they keep their text. Each glyph is made once, here; the
     * placed glyphs (taken by reference, so not copied) are released as they are read, so the two
     * lists are not both held whole.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: float, 4: list<int>, 5: float, 6: float, 7: int}> $placed
     * @param list<int> $sources the index in the text of each normalised code point
     *
     * @return list<Glyph>
     */
    private function glyphs(array &$placed, array $sources): array
    {
        $space   = $this->program->glyphId(0x20);
        $hide    = 0 !== $space && class_exists(IntlChar::class);
        $width   = $hide ? ($this->advance)($space) : 0;
        $at      = 0;
        $cluster = 0;
        $glyphs  = [];
        for ($k = 0; null !== ($entry = $placed[$k] ?? null); ++$k) {
            [$id, $codePoint, $advance, $kerning, $components, $xOffset, $yOffset, $length] = $entry;
            unset($placed[$k]);
            $cluster  = $length > 0 ? $sources[$at] ?? $cluster : $cluster;
            $at       += $length;
            $glyphs[] = $hide
            && $codePoint >= 0xAD // U+00AD, the first default-ignorable code point
            && [] === $components
            && IntlChar::hasBinaryProperty($codePoint, IntlChar::PROPERTY_DEFAULT_IGNORABLE_CODE_POINT)
                ? new Glyph($space, $codePoint, $width, -$width, cluster: $cluster)
                : new Glyph($id, $codePoint, $advance, $kerning, $components, $xOffset, $yOffset, $cluster);
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
     * Glyphs for a substituted sequence, positioned by the font's GPOS (kerning when it has a kern
     * feature, mark and cursive attachment, and the requested features), else kerned from its
     * kern table. A glyph's kerning carries what positioning adds to its advance; a mark
     * positioning gives no advance takes back its whole drawn width.
     *
     * @param list<array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}> $sequence
     * @param list<string>                                                    $features
     *
     * @return list<array{0: int, 1: int, 2: int, 3: float, 4: list<int>, 5: float, 6: float, 7: int}>
     *
     * @throws ParseException
     */
    private function positioned(array $sequence, TextOptions $options, array $features, string $script): array
    {
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
        if (null !== $gpos && $gpos->has($gposFeatures, $script, $language)) {
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
                $language,
            );
        }
        $legacyKern =
            $options->kerning
            && (null === $positions || ! ($gpos?->has(['kern'], $script, $language) ?? false))
            && $this->program->hasKerning();
        $glyphs = [];
        foreach ($sequence as $k => [$id, $codePoints]) {
            [$xAdvance, , $xOffset, $yOffset] = $positions[$k] ?? [$this->program->advance($id), 0, 0, 0];
            $previous = $legacyKern ? $glyphs[$k - 1] ?? null : null;
            if (null !== $previous) {
                $kern = $this->program->kerning($previous[0], $id);
                if (0 !== $kern) {
                    $previous[3]    += $kern * $this->scale;
                    $glyphs[$k - 1] = $previous;
                }
            }
            $glyphs[] = $this->placed($id, $codePoints, $xAdvance, $xOffset, $yOffset);
        }

        return $glyphs;
    }
}
