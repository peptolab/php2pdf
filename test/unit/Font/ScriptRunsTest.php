<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Font\ScriptRuns;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function mb_ord;
use function mb_str_split;

/** Text split into runs of one script for its OpenType features (#33). */
#[Group('unit')]
final class ScriptRunsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: list<array{0: string, 1: int, 2: int}>}> */
    public static function textProvider(): array
    {
        return [
            'one Latin run'                              => ['Hello, world 42.', [['latn', 0, 16]]],
            'punctuation joins the run before'           => ['Ελλάδα, Κύπρος', [['grek', 0, 14]]],
            'scripts change'                             => ['Αβ Где', [['grek', 0, 3], ['cyrl', 3, 3]]],
            'leading common text joins the first script' => ['1. Где', [['cyrl', 0, 6]]],
            'marks inherit their base script'            => ["\u{0430}\u{0301}x", [['cyrl', 0, 2], ['latn', 2, 1]]],
            'text with no script is Latin'               => ['1 + 2', [['latn', 0, 5]]],
            'kana tags'                                  => ['かな', [['kana', 0, 2]]],
            'an empty text'                              => ['', [['latn', 0, 0]]],
        ];
    }

    /** @param list<array{0: string, 1: int, 2: int}> $expected */
    #[Test]
    #[DataProvider('textProvider')]
    public function textSplitsIntoScriptRuns(string $text, array $expected): void
    {
        $codePoints = [];
        foreach (mb_str_split($text, length: 1, encoding: 'UTF-8') as $char) {
            $codePoints[] = (int) mb_ord($char, encoding: 'UTF-8');
        }

        self::assertSame($expected, ScriptRuns::of($codePoints));
    }
}
