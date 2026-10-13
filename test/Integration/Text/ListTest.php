<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text;

use Override;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Text\FlowOptions;
use Php2Pdf\Text\Style\ListMarker;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Textflow;
use Php2Pdf\Text\TextFormat;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function str_repeat;

/** Lists in textflows: markers hanging in each level's indent, from markup or the builder (#35). */
#[Group('integration')]
final class ListTest extends TestCase
{
    /** The left edge of the flows' box. */
    private const float LEFT = 50.0;

    /** The body size: an em, and two the default list indent. */
    private const float SIZE = 10.0;

    private Document $doc;
    private string $path;

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function orderedProvider(): array
    {
        return [
            'numbers from a start' => ['<ol start="9"><li>x</li><li>x</li></ol>', ['9.', '10.']],
            'upper letters'        => ['<ol type="A"><li>x</li><li>x</li></ol>', ['A.', 'B.']],
            'lower roman'          => ['<ol type="i" start="3"><li>x</li><li>x</li></ol>', ['iii.', 'iv.']],
            'a dash'               => ['<ul type="dash"><li>x</li></ul>', ["\u{2013}"]],
            'an unknown type'      => ['<ol type="z"><li>x</li></ol>', ['1.']],
        ];
    }

    /**
     * The left edge of the first word with the text.
     *
     * @param list<array{0: string, 1: float, 2: float, 3: float}> $words
     */
    private static function left(array $words, string $text): float
    {
        foreach ($words as [$word, $left]) {
            if ($word === $text) {
                return $left;
            }
        }
        self::fail("No word {$text}.");
    }

    /** @param list<array{0: string, 1: float, 2: float, 3: float}> $words */
    private static function occurrences(array $words, string $text): int
    {
        $count = 0;
        foreach ($words as [$word]) {
            $count += $word === $text ? 1 : 0;
        }

        return $count;
    }

    /**
     * The right edge of the first word with the text.
     *
     * @param list<array{0: string, 1: float, 2: float, 3: float}> $words
     */
    private static function right(array $words, string $text): float
    {
        foreach ($words as [$word, , $right]) {
            if ($word === $text) {
                return $right;
            }
        }
        self::fail("No word {$text}.");
    }

    #[Test]
    public function aBuiltListIsSetAsMarkupSetsIt(): void
    {
        $flow = $this->doc
            ->textflowBuilder('body')
            ->startList(ListMarker::UpperRoman)
            ->item()
            ->text('One')
            ->startList()
            ->item()
            ->text('Inner')
            ->endList()
            ->paragraph()
            ->text('More')
            ->item()
            ->text('Two')
            ->endList()
            ->paragraph()
            ->text('After')
            ->build();
        $words = $this->fitted($flow);

        self::assertSame(
            ['I.', 'One', "\u{25E6}", 'Inner', 'More', 'II.', 'Two', 'After'],
            array_column($words, 0),
        );
    }

    #[Test]
    public function aListIndentCanBeSet(): void
    {
        $this->doc->styles()->define('wide', new TextStyle(listIndent: 50.0), basedOn: 'body');
        $words = $this->fitted($this->doc->textflow('<ul><li>Item</li></ul>', 'wide', TextFormat::Markup));

        self::assertEqualsWithDelta(self::LEFT + 50.0, self::left($words, 'Item'), 0.01);
    }

    #[Test]
    public function aListIndentShrinksWithTheCopy(): void
    {
        $this->doc->styles()->define('wide', new TextStyle(listIndent: 50.0), basedOn: 'body');
        $words = $this->fitted(
            $this->doc->textflow(
                '<ul><li>An ' . str_repeat('item of many words ', times: 20) . '</li></ul>',
                'wide',
                TextFormat::Markup,
            ),
            12.0,
            new FlowOptions(shrinkToFit: true),
        );

        self::assertLessThan(self::LEFT + 49.0, self::left($words, 'An'));
    }

    #[Test]
    public function anItemBrokenAcrossBoxesShowsItsMarkerOnce(): void
    {
        $words = $this->fitted(
            $this->markup('<ul><li>' . str_repeat('word ', times: 120) . '</li></ul>'),
            40.0,
        );

        self::assertSame(1, self::occurrences($words, "\u{2022}"));
    }

    #[Test]
    public function anItemOutsideAListIsAParagraph(): void
    {
        $words = $this->fitted($this->markup('<li>Loose</li>'));

        self::assertSame([['Loose'], self::LEFT], [array_column($words, 0), self::left($words, 'Loose')]);
    }

    #[Test]
    public function anItemsLaterParagraphsAreSetInItWithoutAMarker(): void
    {
        $words = $this->fitted($this->markup('<ul><li><p>First</p> <p>Second</p></li></ul>'));

        self::assertSame(
            [1, self::left($words, 'First')],
            [self::occurrences($words, "\u{2022}"), self::left($words, 'Second')],
        );
    }

    #[Test]
    public function aRightToLeftItemHangsItsMarkerOnTheRight(): void
    {
        $words = $this->fitted($this->markup("<ul><li>\u{05E9}\u{05DC}\u{05D5}\u{05DD}</li></ul>"));

        self::assertEqualsWithDelta(
            self::LEFT + 300.0 - (2 * self::SIZE) + (0.5 * self::SIZE),
            self::left($words, "\u{2022}"),
            0.01,
        );
    }

    #[Test]
    public function itemsHangTheirMarkersInTheirIndent(): void
    {
        $words = $this->fitted($this->markup(
            '<p>Before</p><ul><li>First <b>item</b></li><li>Second<ul><li>Nested</li></ul></li></ul>',
        ));

        self::assertEqualsWithDelta(
            [
                self::LEFT,
                self::LEFT + (2 * self::SIZE),
                self::LEFT + (2 * self::SIZE) - (0.5 * self::SIZE),
                self::LEFT + (4 * self::SIZE),
                self::LEFT + (4 * self::SIZE) - (0.5 * self::SIZE),
            ],
            [
                self::left($words, 'Before'),
                self::left($words, 'First'),
                self::right($words, "\u{2022}"),
                self::left($words, 'Nested'),
                self::right($words, "\u{25E6}"),
            ],
            0.01,
        );
    }

    /** @param list<string> $labels */
    #[Test]
    #[DataProvider('orderedProvider')]
    public function listsCountInTheirStyle(string $markup, array $labels): void
    {
        $words = [];
        foreach ($this->fitted($this->markup($markup)) as [$word]) {
            if ('x' === $word) {
                continue;
            }
            $words[] = $word;
        }

        self::assertSame($labels, $words);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->path = PdfTools::outputDir() . "/list-{$this->name()}.pdf";
        $this->doc  = new Document($this->path, new DocumentOptions(compress: false));
        $this->doc->styles()->define('body', new TextStyle(
            font: $this->doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf')),
            size: self::SIZE,
        ));
    }

    /**
     * The words of the flow fitted into a box (or as many boxes as it takes, a page each), with
     * their left and right edges.
     *
     * @return list<array{0: string, 1: float, 2: float, 3: float}>
     */
    private function fitted(Textflow $flow, float $height = 500.0, ?FlowOptions $options = null): array
    {
        $pages = 0;
        do {
            ++$pages;
            $this->doc->addPage()->fitTextflow($flow, new Box(self::LEFT, 100.0, 300.0, $height), $options);
        } while (! $flow->isDone());
        $this->doc->close();
        $words = [];
        for ($page = 1; $page <= $pages; ++$page) {
            foreach (PdfTools::words($this->path, $page) as $word) {
                $words[] = $word;
            }
        }

        return $words;
    }

    private function markup(string $markup): Textflow
    {
        return $this->doc->textflow($markup, 'body', TextFormat::Markup);
    }
}
