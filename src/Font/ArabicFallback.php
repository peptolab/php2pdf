<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use Closure;
use IntlChar;
use Normalizer;

use function array_splice;
use function class_exists;
use function count;
use function in_array;
use function mb_chr;
use function mb_ord;
use function mb_str_split;
use function preg_match;

/**
 * Arabic joining for fonts without the joining features, as HarfBuzz falls back to it: each
 * letter's initial, medial, final or isolated presentation form (U+FB50 to U+FEFF) when the font
 * has a glyph for it, and lam with alef as one of the lam-alef ligatures when the font has no
 * required ligatures.
 *
 * @internal
 */
final class ArabicFallback
{
    private const array FORMS = [
        IntlChar::DT_INITIAL  => 'init',
        IntlChar::DT_MEDIAL   => 'medi',
        IntlChar::DT_FINAL    => 'fina',
        IntlChar::DT_ISOLATED => 'isol',
    ];

    /** @var array<int, array<string, int>>|null letter => form => presentation form */
    private static ?array $forms = null;

    /** @var array<int, array<string, int>>|null second letter after lam => lam's form => ligature */
    private static ?array $lamAlef = null;

    /**
     * The sequence with presentation forms for the letters the font has them for.
     *
     * @param list<array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}> $sequence
     * @param list<int>                                                        $codePoints the characters, one per entry
     * @param Closure(int): int                                               $glyphId
     *
     * @return list<array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}>
     */
    public static function apply(array $sequence, array $codePoints, Closure $glyphId): array
    {
        if (! class_exists(IntlChar::class)) {
            return $sequence;
        }
        $forms = self::forms();
        foreach ($sequence as $k => $entry) {
            $form         = $entry[4] ?? '';
            $presentation = $forms[$codePoints[$k] ?? -1][$form] ?? null;
            $glyph        = null === $presentation ? 0 : $glyphId($presentation);
            if (0 !== $glyph) {
                $sequence[$k][0] = $glyph;
            }
        }

        return $sequence;
    }

    /**
     * Lam in an initial or medial form followed by a final alef becomes the lam-alef ligature,
     * isolated or final.
     *
     * @param list<array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}> $sequence
     * @param list<int>                                                        $codePoints
     * @param Closure(int): int                                               $glyphId
     *
     * @return list<array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}>
     */
    public static function lamAlef(array $sequence, array $codePoints, Closure $glyphId): array
    {
        if (! class_exists(IntlChar::class)) {
            return $sequence;
        }
        self::forms();
        $table = self::$lamAlef ?? [];
        for ($k = count($sequence) - 2; $k >= 0; --$k) {
            $lam  = $sequence[$k] ?? null;
            $alef = $sequence[$k + 1] ?? null;
            if (null === $lam || null === $alef || 0x0644 !== ($codePoints[$k] ?? -1) || 'fina' !== ($alef[4] ?? '')) {
                continue;
            }
            $ligature = $table[$codePoints[$k + 1] ?? -1][$lam[4] ?? ''] ?? null;
            $glyph    = null === $ligature ? 0 : $glyphId($ligature);
            if (0 === $glyph) {
                continue;
            }
            array_splice($sequence, $k, length: 2, replacement: [[
                $glyph,
                [...$lam[1], ...$alef[1]],
                0,
                0,
                $lam[4] ?? '',
            ]]);
            array_splice($codePoints, $k, length: 2, replacement: [0x0644]);
        }

        return $sequence;
    }

    /** @return array<int, array<string, int>> */
    private static function forms(): array
    {
        if (null !== self::$forms) {
            return self::$forms;
        }
        $forms   = [];
        $lamAlef = [];
        foreach ([[0xFB50, 0xFDFF], [0xFE70, 0xFEFF]] as [$first, $last]) {
            for ($codePoint = $first; $codePoint <= $last; ++$codePoint) {
                $form =
                    self::FORMS[(int) IntlChar::getIntPropertyValue(
                        $codePoint,
                        IntlChar::PROPERTY_DECOMPOSITION_TYPE,
                    )] ?? null;
                $base = Normalizer::getRawDecomposition(
                    (string) mb_chr($codePoint, encoding: 'UTF-8'),
                    Normalizer::FORM_KD,
                );
                if (null === $form || null === $base || 1 === preg_match('/[\x{0020}\x{064B}-\x{065F}]/u', $base)) {
                    continue;
                }
                $chars = mb_str_split($base, length: 1, encoding: 'UTF-8');
                $one   = (int) mb_ord($chars[0] ?? '', encoding: 'UTF-8');
                if (1 === count($chars)) {
                    $forms[$one][$form] ??= $codePoint;
                    continue;
                }
                $two = (int) mb_ord($chars[1] ?? '', encoding: 'UTF-8');
                if (2 === count($chars) && 0x0644 === $one && in_array($form, ['isol', 'fina'], strict: true)) {
                    $lamAlef[$two]['isol' === $form ? 'init' : 'medi'] = $codePoint;
                }
            }
        }
        self::$lamAlef = $lamAlef;

        return self::$forms = $forms;
    }
}
