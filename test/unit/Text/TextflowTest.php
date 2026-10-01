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
use PhpPdf\Text\Style\Alignment;
use PhpPdf\Text\Style\Leading;
use PhpPdf\Text\Style\TabStop;
use PhpPdf\Text\Style\TextStyle;
use PhpPdf\Text\Textflow;
use PhpPdf\Text\TextFormat;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function end;
use function preg_match_all;
use function str_repeat;
use function substr_count;

final class TextflowTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;

    private static function num(float $v): string
    {
        return Num::format($v, 6);
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
        self::assertSame(
            1,
            substr_count($this->out->contents(), needle: '<0010>'),
            'One hyphen glyph is shown at the break.',
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
    public function flowsAcrossBoxesAndReportsState(): void
    {
        $text = str_repeat('The quick brown fox jumps over the lazy dog. ', times: 12);
        $flow = $this->doc->textflow("<p>{$text}</p><p>Second paragraph.</p>", 'body');
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
        $flow = $this->doc->textflow('<p>aaa bbb ccc ddd eee fff ggg hhh iii jjj kkk lll mmm nnn</p>', $style);
        $this->doc->addPage()->fitTextflow($flow, new Box(0.0, 0.0, 120.0, 200.0));
        $this->doc->close();
        $pdf = $this->out->contents();

        preg_match_all('/\[([^\]]*)\] TJ/', $pdf, $m);
        self::assertGreaterThan(1, count($m[1]));
        self::assertMatchesRegularExpression(
            '/0003> -[0-9.]+ </',
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
    public function measuresWithoutPlacing(): void
    {
        $flow   = $this->doc->textflow('<p>Line one</p><p>Line two</p>', 'body');
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
        $flow = $this->doc->textflow('<p>a<tab>bb<tab>12.5</p>', $style);
        $this->doc->addPage()->fitTextflow($flow, new Box(0.0, 0.0, 300.0, 100.0));
        $this->doc->close();
        $pdf = $this->out->contents();

        $font = $this->doc->fonts()->find('DejaVuSans');
        $bb   = $font->width('bb', 10.0);
        $one2 = $font->width('12', 10.0);
        self::assertStringContainsString('1 0 0 1 ' . self::num(100.0 - $bb) . ' ', $pdf);
        self::assertStringContainsString('1 0 0 1 ' . self::num(180.0 - $one2) . ' ', $pdf);
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
        $flow = $this->doc->textflow('<p>One</p><pagebreak><p>Two</p>', 'body');
        $page = $this->doc->addPage();

        self::assertSame(FlowResult::BoxEmpty, $page->fitTextflow($flow, new Box(0.0, 0.0, 100.0, 3.0)));
        self::assertSame(FlowResult::NextPage, $page->fitTextflow($flow, new Box(0.0, 0.0, 100.0, 100.0)));
        self::assertSame('Two', $flow->remainingText());
        self::assertSame(FlowResult::Done, $page->fitTextflow($flow, new Box(0.0, 0.0, 100.0, 100.0)));
        $this->doc->close();
    }

    #[Test]
    public function requiresAFont(): void
    {
        $this->expectException(PdfException::class);
        $this->doc->textflow('<p>No font</p>')->measure(100.0);
    }

    #[Test]
    public function shrinksToFit(): void
    {
        $flow = $this->doc->textflow('<p>' . str_repeat('word ', times: 60) . '</p>', 'body');
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
