<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text\Bidi;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Font\TextDirection;
use Php2Pdf\Font\TextOptions;
use Php2Pdf\Text\Bidi\BidiRuns;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A line of text cut into runs of one direction, in visual order (#33). */
#[Group('integration')]
final class BidiRunsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: TextDirection, 2: list<array{0: string, 1: bool}>}> */
    public static function lineProvider(): array
    {
        return [
            'Hebrew inside an English line'                => [
                'Read שלום עולם today',
                TextDirection::Auto,
                [['Read ', false], ['שלום עולם', true], [' today', false]],
            ],
            'a right-to-left line with English and digits' => [
                'שלום abc 123',
                TextDirection::Auto,
                [['abc 123', false], ['שלום ', true]],
            ],
            'brackets keep the line direction'             => [
                '(אב)',
                TextDirection::LeftToRight,
                [['(', false], ['אב', true], [')', false]],
            ],
            'Latin in a right-to-left line'                => ['abc', TextDirection::RightToLeft, [['abc', false]]],
            'an empty line'                                => ['', TextDirection::RightToLeft, []],
        ];
    }

    #[Test]
    public function aLineIsShapedInVisualOrder(): void
    {
        $font = (new Document(new MemoryOutput()))->fonts()
            ->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $text = '';
        foreach (BidiRuns::shape($font, 'ab אב (ג)', new TextOptions())->glyphs as $glyph) {
            $text .= $glyph->text();
        }

        self::assertSame('ab (ג) בא', $text);
    }

    /** @param list<array{0: string, 1: bool}> $expected */
    #[Test]
    #[DataProvider('lineProvider')]
    public function linesSplitIntoVisualRuns(string $text, TextDirection $direction, array $expected): void
    {
        self::assertSame($expected, BidiRuns::of($text, $direction));
    }

    #[Test]
    public function onlyRightToLeftTextOrLinesNeedTheAlgorithm(): void
    {
        self::assertSame(
            [false, true, true, true],
            [
                BidiRuns::needed('Plain text, 123.', TextDirection::Auto),
                BidiRuns::needed('Plain text', TextDirection::RightToLeft),
                BidiRuns::needed('שלום', TextDirection::Auto),
                BidiRuns::needed("a\u{202E}b", TextDirection::LeftToRight),
            ],
        );
    }
}
