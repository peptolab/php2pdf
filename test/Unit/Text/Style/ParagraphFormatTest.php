<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Style;

use Php2Pdf\Font\TextDirection;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\Composer;
use Php2Pdf\Text\Style\ParagraphFormat;
use Php2Pdf\Text\Style\TabStop;
use Php2Pdf\Text\Style\TextStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ParagraphFormatTest extends TestCase
{
    /** @return array<string, array{0: TextStyle, 1: int, 2: Alignment, 3: Alignment}> */
    public static function alignmentProvider(): array
    {
        return [
            'unset, left to right'          => [new TextStyle(), 0, Alignment::Left, Alignment::Left],
            'unset, right to left'          => [new TextStyle(), 1, Alignment::Right, Alignment::Right],
            'centred'                       => [
                new TextStyle(alignment: Alignment::Center),
                1,
                Alignment::Center,
                Alignment::Center,
            ],
            'justified, last line at start' => [
                new TextStyle(alignment: Alignment::Justify),
                1,
                Alignment::Justify,
                Alignment::Right,
            ],
            'justified, last line set'      => [
                new TextStyle(
                    alignment: Alignment::Justify,
                    lastLineAlignment: Alignment::Center,
                ),
                0,
                Alignment::Justify,
                Alignment::Center,
            ],
        ];
    }

    /** @return array<string, array{0: TextStyle, 1: Composer}> */
    public static function composerProvider(): array
    {
        return [
            'justified text: the paragraph composer' => [
                new TextStyle(alignment: Alignment::Justify),
                Composer::Paragraph,
            ],
            'other text: line by line'               => [
                new TextStyle(alignment: Alignment::Left),
                Composer::SingleLine,
            ],
            'as set'                                 => [
                new TextStyle(
                    alignment: Alignment::Left,
                    composer: Composer::Paragraph,
                ),
                Composer::Paragraph,
            ],
        ];
    }

    #[Test]
    #[DataProvider('alignmentProvider')]
    public function alignmentFollowsTheStyleOrTheParagraphsStartSide(
        TextStyle $style,
        int $level,
        Alignment $alignment,
        Alignment $lastLine,
    ): void {
        $format = ParagraphFormat::of($style);

        self::assertSame([$alignment, $lastLine], [$format->alignment($level), $format->lastLineAlignment($level)]);
    }

    #[Test]
    public function anUnsetStyleHasTheDefaults(): void
    {
        $format = ParagraphFormat::of(new TextStyle());

        self::assertSame(
            [0.0, 0.0, 0.0, 0.0, 0.0, false, null, 6, 2, 2, [], 36.0, false, false, 2, 2, TextDirection::Auto],
            [
                $format->firstLineIndent,
                $format->leftIndent,
                $format->rightIndent,
                $format->spaceBefore,
                $format->spaceAfter,
                $format->hyphenate,
                $format->language,
                $format->minHyphenatedWordLength,
                $format->hyphenLeftMin,
                $format->hyphenRightMin,
                $format->tabs,
                $format->defaultTabWidth,
                $format->keepWithNext,
                $format->keepTogether,
                $format->orphans,
                $format->widows,
                $format->direction,
            ],
        );
    }

    #[Test]
    public function aSetStyleKeepsItsValues(): void
    {
        $tab    = TabStop::left(72.0);
        $format = ParagraphFormat::of(new TextStyle(
            firstLineIndent: 12.0,
            leftIndent: 6.0,
            rightIndent: 3.0,
            spaceBefore: 4.0,
            spaceAfter: 8.0,
            hyphenate: true,
            language: 'en',
            minHyphenatedWordLength: 7,
            hyphenLeftMin: 3,
            hyphenRightMin: 4,
            tabs: [$tab],
            defaultTabWidth: 18.0,
            keepWithNext: true,
            keepTogether: true,
            orphans: 3,
            widows: 4,
            direction: TextDirection::RightToLeft,
        ));

        self::assertSame(
            [12.0, 6.0, 3.0, 4.0, 8.0, true, 'en', 7, 3, 4, [$tab], 18.0, true, true, 3, 4, TextDirection::RightToLeft],
            [
                $format->firstLineIndent,
                $format->leftIndent,
                $format->rightIndent,
                $format->spaceBefore,
                $format->spaceAfter,
                $format->hyphenate,
                $format->language,
                $format->minHyphenatedWordLength,
                $format->hyphenLeftMin,
                $format->hyphenRightMin,
                $format->tabs,
                $format->defaultTabWidth,
                $format->keepWithNext,
                $format->keepTogether,
                $format->orphans,
                $format->widows,
                $format->direction,
            ],
        );
    }

    #[Test]
    public function aStyleIsResolvedOnce(): void
    {
        $style = new TextStyle(leftIndent: 5.0);

        self::assertSame(ParagraphFormat::of($style), ParagraphFormat::of($style));
    }

    #[Test]
    public function stylesResolvingAlikeShareOneFormat(): void
    {
        self::assertSame(
            [true, false],
            [
                ParagraphFormat::of(new TextStyle(widows: 3)) === ParagraphFormat::of(new TextStyle(widows: 3)),
                ParagraphFormat::of(new TextStyle(widows: 3)) === ParagraphFormat::of(new TextStyle(widows: 4)),
            ],
        );
    }

    #[Test]
    #[DataProvider('composerProvider')]
    public function theComposerFollowsTheAlignmentUnlessSet(TextStyle $style, Composer $composer): void
    {
        self::assertSame($composer, ParagraphFormat::of($style)->composer);
    }
}
