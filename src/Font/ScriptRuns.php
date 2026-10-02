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
        $scripts = [];
        foreach ($codePoints as $codePoint) {
            $scripts[] = $codePoint < 0x80 && ! self::isLetter($codePoint) ? '' : self::tag($codePoint);
        }
        $first = 'latn';
        foreach ($scripts as $script) {
            if ('' === $script) {
                continue;
            }
            $first = $script;
            break;
        }
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

    private static function isLetter(int $codePoint): bool
    {
        return ($codePoint >= 0x41 && $codePoint <= 0x5A) || ($codePoint >= 0x61 && $codePoint <= 0x7A);
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
