<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text\Flow;

use Override;
use Php2Pdf\Text\Flow\WordBreaks;
use Php2PdfTest\Trait\KerningFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;

/**
 * Lines of Chinese and Japanese break between characters, as UAX #14 and the Japanese kinsoku
 * rules allow: not before closing punctuation, small kana and marks, nor after opening brackets.
 * Korean, which spaces its words, breaks at its spaces only.
 */
#[Group('integration')]
final class WordBreaksTest extends TestCase
{
    use KerningFixtureTrait;

    /** @return array<string, array{0: string, 1: list<string>}> the text, and its pieces */
    public static function cjkProvider(): array
    {
        return [
            'between ideographs'                               => ['中文排版', ['中', '文', '排', '版']],
            'closing punctuation with the character before'    => ['版。字、', ['版。', '字、']],
            'an opening bracket with the character after'      => ['字「引」', ['字', '「引」']],
            'small kana with the character before'             => ['ちょっと', ['ちょっ', 'と']],
            'a prolonged sound mark with the character before' => ['テーブル', ['テー', 'ブ', 'ル']],
            'Latin before kana'                                => ['PDFを', ['PDF', 'を']],
            'digits before an ideograph'                       => ['2023年', ['2023', '年']],
            'full-width brackets round Latin'                  => ['a（b）c', ['a', '（b）', 'c']],
            'a variation selector with its ideograph'          => ["葛\u{E0101}城", ["葛\u{E0101}", '城']],
            'Korean, at its spaces only'                       => ['한국어', ['한국어']],
        ];
    }

    /** @param list<string> $pieces */
    #[Test]
    #[DataProvider('cjkProvider')]
    public function chineseAndJapaneseBreakBetweenCharacters(string $text, array $pieces): void
    {
        [$cut] = (new WordBreaks())->pieces($text, $this->style, 0);

        self::assertSame($pieces, array_column($cut, column_key: 0));
    }

    #[Override]
    protected function setUp(): void
    {
        [$this->serif, $this->style, $this->hyphenators] = self::kerning();
    }
}
