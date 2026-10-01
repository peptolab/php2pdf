<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Text;

use PhpPdf\Color\Rgb;
use PhpPdf\Core\FixedClock;
use PhpPdf\Core\Num;
use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Exception\PdfException;
use PhpPdf\Geometry\Box;
use PhpPdf\Text\FlowOptions;
use PhpPdf\Text\FlowResult;
use PhpPdf\Text\Hyphenation\Hyphenator;
use PhpPdf\Text\Markup;
use PhpPdf\Text\Style\Alignment;
use PhpPdf\Text\Style\Leading;
use PhpPdf\Text\Style\TabStop;
use PhpPdf\Text\Style\TextStyle;
use PhpPdf\Text\Textflow;
use PhpPdf\Text\TextFormat;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function end;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function str_repeat;
use function substr_count;

final class TextflowTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;

    /** @return array<string, array{0: Alignment, 1: string, 2: float}> */
    public static function textEndProvider(): array
    {
        return [
            'left'  => [Alignment::Left, 'Lead in', 50.0],
            'right' => [Alignment::Right, 'Lead in', 250.0],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function unknownTagProvider(): array
    {
        return [
            'opening' => ['<p>Tom <Jerry></p>'],
            'closing' => ['<p>Tom </jerry></p>'],
        ];
    }

    private static function num(float $v): string
    {
        return Num::format($v, 6);
    }

    #[Test]
    public function aLineIsAsTallAsItsTallestStyle(): void
    {
        $flow = Textflow::build($this->doc, 'body')
            ->paragraph()
            ->text('small ')
            ->text('big', new TextStyle(size: 20.0))
            ->text(' small again')
            ->build();
        $font = $this->doc->fonts()->find('DejaVuSans');

        self::assertEqualsWithDelta($font->ascender(20.0) - $font->descender(20.0), $flow->measure(300.0), 1.0e-9);
    }

    #[Test]
    public function breaksWordsAtSoftHyphensAndPatterns(): void
    {
        $hyphenator = new Hyphenator(['hy3ph', 'he2n', 'hena4', 'hen5at', '1na', 'n2at', '1tio', '2io', 'o2n']);
        $style      = new TextStyle(
            font: 'DejaVuSans',
            size: 10.0,
            hyphenate: true,
            language: 'en',
        );
        $flow  = Textflow::fromMarkup($this->doc, '<p>hyphenation hyphenation</p>', $style, ['en' => $hyphenator]);
        $width = $this->doc->fonts()->find('DejaVuSans')->width('hyphenation hyphen-', 10.0);
        $this->doc->addPage()->fitTextflow($flow, new Box(0.0, 0.0, $width + 0.5, 100.0));
        self::assertSame(2, $flow->lastFit()?->lineCount);
        $this->doc->close();
        preg_match('/stream\n(.*?)\nendstream/s', $this->out->contents(), $content);
        self::assertSame(
            1,
            substr_count($content[1] ?? '', needle: "\x00\x10"),
            'One hyphen glyph (id 16) is shown at the break.',
        );
    }

    #[Test]
    public function buildsFlowsInCode(): void
    {
        $flow = Textflow::build($this->doc, 'body')
            ->paragraph('h1')
            ->text('Title')
            ->paragraph()
            ->text('Plain ')
            ->bold('bold')
            ->text(' and ')
            ->text('red', new TextStyle(color: Rgb::fromHex('#f00')))
            ->lineBreak()
            ->text('after break')
            ->build();

        self::assertSame("Title\n\nPlain bold and red\nafter break", $flow->remainingText());
        $this->doc->addPage()->fitTextflow($flow, new Box(0.0, 0.0, 300.0, 300.0));
        $this->doc->close();
        self::assertStringContainsString('1 0 0 rg', $this->out->contents());
        self::assertStringContainsString('/F2 ', $this->out->contents(), 'The bold face is a second font.');
    }

    #[Test]
    public function documentTextflowsTakePlainTextByDefault(): void
    {
        $flow = $this->doc->textflow('Tom <Jerry> & R&amp;D Ltd', 'body');

        self::assertSame('Tom <Jerry> & R&amp;D Ltd', $flow->remainingText());
    }

    #[Test]
    public function escapedDataRoundTripsThroughMarkup(): void
    {
        $data = 'Tom <Jerry> & R&amp;D Ltd';
        $flow = $this->doc->textflow('<p>' . Markup::escape($data) . '</p>', 'body', TextFormat::Markup);

        self::assertSame($data, $flow->remainingText());
    }

    #[Test]
    public function flowsAcrossBoxesAndReportsState(): void
    {
        $text = str_repeat('The quick brown fox jumps over the lazy dog. ', times: 12);
        $flow = $this->doc->textflow("<p>{$text}</p><p>Second paragraph.</p>", 'body', TextFormat::Markup);
        $page = $this->doc->addPage();

        $first = $page->fitTextflow($flow, new Box(50.0, 500.0, 200.0, 50.0));
        self::assertSame(FlowResult::BoxFull, $first);
        self::assertFalse($flow->isDone());
        $info = $flow->lastFit();
        self::assertNotNull($info);
        self::assertSame(4, $info->lineCount, 'Four 12pt lines fit in 50pt with the first baseline at the ascender.');
        self::assertTrue($info->overflowed());
        self::assertEqualsWithDelta(550.0 - 9.282, $info->firstBaseline[1] ?? 0.0, 0.01);
        self::assertStringContainsString('Second paragraph.', $flow->remainingText());

        $second = $page->fitTextflow($flow, new Box(300.0, 300.0, 200.0, 400.0));
        self::assertSame(FlowResult::Done, $second);
        self::assertTrue($flow->isDone());
        self::assertSame('', $flow->remainingText());
        $this->doc->close();

        self::assertStringContainsString('BT /F1 10 Tf', $this->out->contents());
    }

    #[Test]
    public function justifiesWithAdjustedSpacesAndAlignsLastLine(): void
    {
        $style = new TextStyle(
            font: 'DejaVuSans',
            size: 10.0,
            alignment: Alignment::Justify,
            lastLineAlignment: Alignment::Right,
        );
        $flow = $this->doc->textflow(
            '<p>aaa bbb ccc ddd eee fff ggg hhh iii jjj kkk lll mmm nnn</p>',
            $style,
            TextFormat::Markup,
        );
        $this->doc->addPage()->fitTextflow($flow, new Box(0.0, 0.0, 120.0, 200.0));
        $this->doc->close();
        $pdf = $this->out->contents();

        preg_match_all('/\[([^\]]*)\] TJ/', $pdf, $m);
        self::assertGreaterThan(1, count($m[1]));
        self::assertMatchesRegularExpression(
            '/\x00\x03\) -[0-9.]+ \(/',
            $m[1][0],
            'Justified lines carry negative adjustments after space glyphs.',
        );
        $lastLine = end($m[1]);
        self::assertStringNotContainsString(
            '> -',
            false === $lastLine ? '' : $lastLine,
            'The last line is not justified.',
        );
    }

    #[Test]
    public function markupDropsUnknownTags(): void
    {
        $flow = $this->doc->textflow('<p>Tom <jerry>and</jerry> friends</p>', 'body', TextFormat::Markup);

        self::assertSame('Tom and friends', $flow->remainingText());
    }

    #[Test]
    public function measuresWithoutPlacing(): void
    {
        $flow   = $this->doc->textflow('<p>Line one</p><p>Line two</p>', 'body', TextFormat::Markup);
        $height = $flow->measure(300.0);

        self::assertEqualsWithDelta(
            9.282 + 12.0 + 2.363,
            $height,
            0.01,
            'Ascender, one line of leading, then the descender.',
        );
        self::assertFalse($flow->isDone());
    }

    #[Test]
    public function placesTabs(): void
    {
        $style = new TextStyle(
            font: 'DejaVuSans',
            size: 10.0,
            tabs: [TabStop::right(100.0), TabStop::decimal(180.0)],
        );
        $flow = $this->doc->textflow('<p>a<tab>bb<tab>12.5</p>', $style, TextFormat::Markup);
        $this->doc->addPage()->fitTextflow($flow, new Box(0.0, 0.0, 300.0, 100.0));
        $this->doc->close();
        $pdf = $this->out->contents();

        $font = $this->doc->fonts()->find('DejaVuSans');
        $bb   = $font->width('bb', 10.0);
        $one2 = $font->width('12', 10.0);
        self::assertMatchesRegularExpression(
            '/ ' . preg_quote(self::num(100.0 - $bb), delimiter: '/') . ' [0-9.]+ Td /',
            $pdf,
        );
        self::assertMatchesRegularExpression(
            '/ ' . preg_quote(self::num(180.0 - $one2), delimiter: '/') . ' [0-9.]+ Td /',
            $pdf,
        );
    }

    #[Test]
    public function plainTextBreaksLinesAtSingleLineEndsAndParagraphsAtBlankLines(): void
    {
        $flow = $this->doc->textflow("one\ntwo\n\nthree", 'body', TextFormat::Plain);
        $page = $this->doc->addPage();
        $page->fitTextflow($flow, new Box(0.0, 0.0, 300.0, 200.0));
        self::assertSame(3, $flow->lastFit()?->lineCount);
    }

    #[Test]
    public function plainTextSplitsParagraphsOnBlankLines(): void
    {
        $flow = $this->doc->textflow("First <b>not bold</b>\nstill first\n\nSecond", 'body', TextFormat::Plain);

        self::assertSame("First <b>not bold</b>\nstill first\n\nSecond", $flow->remainingText());
    }

    #[Test]
    public function reportsEmptyBoxesAndPageBreaks(): void
    {
        $flow = $this->doc->textflow('<p>One</p><pagebreak><p>Two</p>', 'body', TextFormat::Markup);
        $page = $this->doc->addPage();

        self::assertSame(FlowResult::BoxEmpty, $page->fitTextflow($flow, new Box(0.0, 0.0, 100.0, 3.0)));
        self::assertSame(FlowResult::NextPage, $page->fitTextflow($flow, new Box(0.0, 0.0, 100.0, 100.0)));
        self::assertSame('Two', $flow->remainingText());
        self::assertSame(FlowResult::Done, $page->fitTextflow($flow, new Box(0.0, 0.0, 100.0, 100.0)));
        $this->doc->close();
    }

    /** The text end lies on the last baseline, where the last line's text stops. */
    #[Test]
    #[DataProvider('textEndProvider')]
    public function reportsWhereTheTextEnds(Alignment $alignment, string $text, float $edge): void
    {
        $style = new TextStyle(
            font: 'DejaVuSans',
            size: 10.0,
            alignment: $alignment,
        );
        $flow  = $this->doc->textflow("First line of the flow.\n{$text}", $style);
        $this->doc->addPage()->fitTextflow($flow, new Box(50.0, 500.0, 200.0, 100.0));
        $info  = $flow->lastFit();
        $width = $this->doc->fonts()->find('DejaVuSans')->width($text, 10.0);

        self::assertEqualsWithDelta(
            [Alignment::Right === $alignment ? $edge : $edge + $width, $info?->lastBaseline[1]],
            $info?->textEnd,
            1.0e-6,
        );
    }

    #[Test]
    public function requiresAFont(): void
    {
        $this->expectException(PdfException::class);
        $this->doc->textflow('<p>No font</p>', null, TextFormat::Markup)->measure(100.0);
    }

    #[Test]
    public function risenTextRaisesTheLine(): void
    {
        $flow = Textflow::build($this->doc, 'body')
            ->paragraph()
            ->text('base ')
            ->text('up', new TextStyle(rise: 5.0))
            ->text(' base')
            ->build();
        $font = $this->doc->fonts()->find('DejaVuSans');

        self::assertEqualsWithDelta(
            $font->ascender(10.0) + 5.0 - $font->descender(10.0),
            $flow->measure(300.0),
            1.0e-9,
        );
    }

    #[Test]
    public function shrinksToFit(): void
    {
        $flow = $this->doc->textflow('<p>' . str_repeat('word ', times: 60) . '</p>', 'body', TextFormat::Markup);
        $box  = new Box(0.0, 0.0, 200.0, 60.0);
        self::assertSame(FlowResult::Done, $this->doc->addPage()->fitTextflow(
            $flow,
            $box,
            FlowOptions::shrinkToFit(0.3),
        ));
        $scale = $flow->lastFit()->scale ?? 1.0;
        self::assertLessThan(1.0, $scale);
        self::assertGreaterThan(0.3, $scale);
        $this->doc->close();
    }

    #[Test]
    public function strictMarkupAcceptsEveryKnownTag(): void
    {
        $markup = '<p>a <b>b</b> <i>i</i> <u>u</u> <s>s</s> x<sup>2</sup> H<sub>2</sub>O <span>s</span><br><tab>t</p><columnbreak><pagebreak>';
        $flow   = $this->doc->textflow($markup, 'body', TextFormat::StrictMarkup);

        self::assertStringStartsWith('a b i u s x2 H2O s', $flow->remainingText());
    }

    #[Test]
    #[DataProvider('unknownTagProvider')]
    public function strictMarkupRejectsUnknownTags(string $markup): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Unknown tag');
        $this->doc->textflow($markup, 'body', TextFormat::StrictMarkup);
    }

    #[Test]
    public function stylesInheritAndResolveFontsByName(): void
    {
        $h1 = $this->doc->styles()->get('h1');

        self::assertSame(14.0, $h1->size);
        self::assertSame('DejaVuSans-Bold', $h1->font);
        self::assertSame('DejaVuSans-Bold', $h1->bold, 'Inherited from body.');
        self::assertSame(12.0, $h1->leading?->resolve(14.0));
        self::assertSame(16.8, Leading::percent(120.0)->resolve(14.0));
    }

    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(
            clock: new FixedClock('2026-01-01'),
            compress: false,
            xmp: false,
        ));
        $this->doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'));
        $this->doc->styles()->define('body', new TextStyle(
            font: 'DejaVuSans',
            bold: 'DejaVuSans-Bold',
            size: 10.0,
            leading: Leading::pt(12.0),
        ));
        $this->doc->styles()->define(
            'h1',
            new TextStyle(
                font: 'DejaVuSans-Bold',
                size: 14.0,
                spaceAfter: 4.0,
            ),
            basedOn: 'body',
        );
    }
}
