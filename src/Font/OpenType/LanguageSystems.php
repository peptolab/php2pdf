<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use function explode;
use function str_replace;
use function strtolower;

/**
 * OpenType language system tags for languages, by their BCP 47 tag: what a font's localised
 * forms (locl) and language-specific lookups are filed under. "tr" and "tr-TR" are Turkish (TRK).
 *
 * @internal
 */
final class LanguageSystems
{
    /** Primary language subtags and the language systems they map to. */
    private const array TAGS = [
        'af'  => 'AFK ',
        'ar'  => 'ARA ',
        'az'  => 'AZE ',
        'be'  => 'BEL ',
        'bg'  => 'BGR ',
        'bs'  => 'BOS ',
        'ca'  => 'CAT ',
        'crh' => 'CRT ',
        'cs'  => 'CSY ',
        'cy'  => 'WEL ',
        'da'  => 'DAN ',
        'de'  => 'DEU ',
        'el'  => 'ELL ',
        'en'  => 'ENG ',
        'es'  => 'ESP ',
        'et'  => 'ETI ',
        'eu'  => 'EUQ ',
        'fa'  => 'FAR ',
        'fi'  => 'FIN ',
        'fr'  => 'FRA ',
        'ga'  => 'IRI ',
        'gd'  => 'GAE ',
        'gl'  => 'GAL ',
        'he'  => 'IWR ',
        'hi'  => 'HIN ',
        'hr'  => 'HRV ',
        'hu'  => 'HUN ',
        'hy'  => 'HYE ',
        'id'  => 'IND ',
        'is'  => 'ISL ',
        'it'  => 'ITA ',
        'ja'  => 'JAN ',
        'ka'  => 'KAT ',
        'kk'  => 'KAZ ',
        'ko'  => 'KOR ',
        'ku'  => 'KUR ',
        'lt'  => 'LTH ',
        'lv'  => 'LVI ',
        'mk'  => 'MKD ',
        'mn'  => 'MNG ',
        'ms'  => 'MLY ',
        'mt'  => 'MTS ',
        'nb'  => 'NOR ',
        'nl'  => 'NLD ',
        'nn'  => 'NYN ',
        'no'  => 'NOR ',
        'pl'  => 'PLK ',
        'pt'  => 'PTG ',
        'ro'  => 'ROM ',
        'ru'  => 'RUS ',
        'sk'  => 'SKY ',
        'sl'  => 'SLV ',
        'sq'  => 'SQI ',
        'sr'  => 'SRB ',
        'sv'  => 'SVE ',
        'tr'  => 'TRK ',
        'tt'  => 'TAT ',
        'uk'  => 'UKR ',
        'ur'  => 'URD ',
        'vi'  => 'VIT ',
        'zh'  => 'ZHS ',
    ];

    /** The language system of a language, or null when it has none here. */
    public static function forLanguage(?string $language): ?string
    {
        if (null === $language || '' === $language) {
            return null;
        }
        $primary = explode('-', strtolower(str_replace('_', replace: '-', subject: $language)))[0];

        return self::TAGS[$primary] ?? null;
    }
}
