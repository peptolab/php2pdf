<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use IntlChar;

use function array_key_exists;
use function class_exists;
use function count;
use function strtolower;

/**
 * Splits text into runs of one script, each with its OpenType script tag, so a font's features
 * for Greek or Cyrillic apply to that text. Characters common to all scripts (spaces, digits,
 * punctuation) and inherited ones (combining marks) belong to the run around them. The script
 * property comes from ext-intl; without it all text is one Latin run.
 *
 * @internal
 */
final class ScriptRuns
{
    /** ISO 15924 codes whose OpenType tags are not the code in lower case. */
    private const array TAGS = [
        'Hira' => 'kana',
        'Laoo' => 'lao ',
        'Yiii' => 'yi  ',
        'Nkoo' => 'nko ',
        'Vaii' => 'vai ',
        'Zyyy' => '',
        'Zinh' => '',
        'Zzzz' => '',
    ];

    /** @var array<int, string> ICU script value => tag ('' for common and inherited) */
    private static array $tags = [];

    /** The ISO 15924 code (in lower case) of a script's OpenType tag. */
    public static function iso(string $tag): string
    {
        return match ($tag) {
            'lao '  => 'laoo',
            'yi  '  => 'yiii',
            'nko '  => 'nkoo',
            'vai '  => 'vaii',
            default => $tag,
        };
    }

    /**
     * The runs of a sequence of code points: tag, first index and length.
     *
     * @param list<int> $codePoints
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    public static function of(array $codePoints): array
    {
        $count = count($codePoints);
        if (! class_exists(IntlChar::class) || 0 === $count) {
            return [['latn', 0, $count]];
        }
        // ASCII letters are Latin and the rest of ASCII is common, so only the rest needs ICU.
        // Setting bit 0x20 folds A-Z onto a-z and nothing else in ASCII onto that range.
        $scripts = [];
        $first   = null;
        foreach ($codePoints as $codePoint) {
            $lower  = $codePoint | 0x20;
            $script = $lower >= 0x61 && $lower <= 0x7A ? 'latn' : '';
            if ($codePoint >= 0x80) {
                $script = self::tag($codePoint);
            }
            $first     ??= '' === $script ? null : $script;
            $scripts[] = $script;
        }
        $first   ??= 'latn';
        $runs    = [];
        $current = $first;
        $start   = 0;
        foreach ($scripts as $i => $script) {
            if ('' === $script || $script === $current) {
                continue;
            }
            $runs[]  = [$current, $start, $i - $start];
            $current = $script;
            $start   = $i;
        }
        $runs[] = [$current, $start, $count - $start];

        return $runs;
    }

    private static function tag(int $codePoint): string
    {
        $value = (int) IntlChar::getIntPropertyValue($codePoint, IntlChar::PROPERTY_SCRIPT);
        if (array_key_exists($value, self::$tags)) {
            return self::$tags[$value];
        }
        $code = (string) IntlChar::getPropertyValueName(
            IntlChar::PROPERTY_SCRIPT,
            $value,
            IntlChar::SHORT_PROPERTY_NAME,
        );

        return self::$tags[$value] = self::TAGS[$code] ?? strtolower($code);
    }
}
