<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Font\Normaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function in_array;
use function mb_ord;
use function mb_str_split;

/**
 * Text normalised for a font as HarfBuzz does (#33). The font here has a, e, o, s, n, A and Ω,
 * the precomposed á, ñ, ạ and Å, and the combining acute, circumflex, tilde, dot below, dot
 * above, ogonek, macron and ring.
 */
#[Group('unit')]
final class NormaliserTest extends TestCase
{
    private const string FONT = "aeosnAΩáñạÅ\u{0301}\u{0302}\u{0303}\u{0323}\u{0307}\u{0328}\u{0304}\u{030A}";

    /** @return array<string, array{string, list<int>}> */
    public static function sourceProvider(): array
    {
        return [
            'unchanged'                       => ['abc', [0, 1, 2]],
            'a mark composed into its letter' => ["xa\u{0301}y", [0, 1, 3]],
            'a letter the font lacks'         => ["x\u{00E3}y", [0, 1, 1, 2]],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function textProvider(): array
    {
        return [
            'a letter and a mark compose'             => ["a\u{0301}", 'á'],
            'no composed glyph: they stay apart'      => ["e\u{0301}", "e\u{0301}"],
            'a precomposed letter the font lacks'     => ['é', "e\u{0301}"],
            'marks in canonical order, then composed' => ["a\u{0302}\u{0323}", "ạ\u{0302}"],
            'a blocked mark stays'                    => ["a\u{0323}\u{0323}\u{0301}", "ạ\u{0323}\u{0301}"],
            'a singleton decomposes'                  => ["\u{2126}", 'Ω'],
            'decomposition and recomposition'         => ["\u{212B}", 'Å'],
            'deep decomposition for a missing glyph'  => ['ǭ', "o\u{0328}\u{0304}"],
            'text without marks stays'                => ['seen', 'seen'],
        ];
    }

    /** @return list<int> */
    private static function codePoints(string $text): array
    {
        $out = [];
        foreach (mb_str_split($text, length: 1, encoding: 'UTF-8') as $char) {
            $out[] = (int) mb_ord($char, encoding: 'UTF-8');
        }

        return $out;
    }

    /** @param list<int> $sources */
    #[Test]
    #[DataProvider('sourceProvider')]
    public function eachCharacterKnowsWhichCharacterItCameFrom(string $text, array $sources): void
    {
        $font = self::codePoints(self::FONT);

        self::assertSame(
            $sources,
            Normaliser::normalise(self::codePoints($text), static fn(int $c): bool => in_array(
                $c,
                $font,
                strict: true,
            ))[1],
        );
    }

    #[Test]
    #[DataProvider('textProvider')]
    public function textIsNormalisedForTheFont(string $text, string $expected): void
    {
        $font = self::codePoints(self::FONT);

        self::assertSame(
            self::codePoints($expected),
            Normaliser::normalise(self::codePoints($text), static fn(int $c): bool => in_array(
                $c,
                $font,
                strict: true,
            ))[0],
        );
    }
}
