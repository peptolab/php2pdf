<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Font\ArabicJoining;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function mb_ord;
use function mb_str_split;

/** Joining forms of Arabic and Syriac letters, as HarfBuzz's Arabic shaper gives them (#33). */
#[Group('unit')]
final class ArabicJoiningTest extends TestCase
{
    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function textProvider(): array
    {
        return [
            'a word joins through'                => ['بسم', ['init', 'medi', 'fina']],
            'right-joining letters stop it'       => ['دار', ['isol', 'isol', 'isol']],
            'alef joins only to the right'        => ['با', ['init', 'fina']],
            'marks are passed over'               => ["ب\u{064C}ب", ['init', '', 'fina']],
            'a zero-width non-joiner breaks'      => ["ب\u{200C}ب", ['isol', '', 'isol']],
            'a zero-width joiner joins'           => ["ب\u{200D}", ['init', 'fina']],
            'spaces break'                        => ['ب ب', ['isol', '', 'isol']],
            'Syriac alaph after a joining letter' => ['ܒܐ', ['init', 'fina']],
            'Syriac alaph after dalath'           => ['ܕܐ', ['isol', 'fin3']],
            'Syriac alaph after alaph'            => ['ܐܐ', ['isol', 'fin2']],
            'Syriac final alaph medial'           => ['ܒܐܒ', ['init', 'med2', 'isol']],
            'a left-joining letter joins onward'  => ["\u{A872}\u{A840}", ['init', 'fina']],
        ];
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('textProvider')]
    public function lettersTakeTheirJoiningForms(string $text, array $expected): void
    {
        $codePoints = [];
        foreach (mb_str_split($text, length: 1, encoding: 'UTF-8') as $char) {
            $codePoints[] = (int) mb_ord($char, encoding: 'UTF-8');
        }

        self::assertSame($expected, ArabicJoining::forms($codePoints));
    }
}
