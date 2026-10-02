<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Font\TextDirection;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\TextFormat;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match_all;

/** Right-to-left paragraphs in a Textflow (#33). */
#[Group('integration')]
final class RightToLeftTextflowTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;

    /** @return array<string, array{0: string, 1: TextStyle, 2: bool}> */
    public static function alignmentProvider(): array
    {
        return [
            'a Hebrew paragraph aligns right'         => ['<p>שלום עולם</p>', new TextStyle(), true],
            'an English paragraph aligns left'        => ['<p>Hello world</p>', new TextStyle(), false],
            'a right-to-left style aligns right'      => [
                '<p>Hello world</p>',
                new TextStyle(direction: TextDirection::RightToLeft),
                true,
            ],
            'an alignment set wins'                   => [
                '<p>שלום עולם</p>',
                new TextStyle(alignment: Alignment::Left),
                false,
            ],
            'a left-to-right style keeps Hebrew left' => [
                '<p>שלום עולם</p>',
                new TextStyle(direction: TextDirection::LeftToRight),
                false,
            ],
        ];
    }

    #[Test]
    #[DataProvider('alignmentProvider')]
    public function rightToLeftParagraphsAlignRightByDefault(string $markup, TextStyle $style, bool $right): void
    {
        $flow = $this->doc->textflow($markup, $this->style()->merge($style), TextFormat::Markup);
        $this->doc->addPage(new Dimensions(300.0, 100.0))->fitTextflow($flow, new Box(0.0, 0.0, 300.0, 100.0));

        self::assertSame($right, ($flow->lastFit()?->firstBaseline[0] ?? 0.0) > 100.0);
    }

    #[Test]
    public function wordsAreSetFromTheRight(): void
    {
        $this->doc->styles()->define('big', new TextStyle(size: 20.0));
        $flow = $this->doc->textflow(
            '<p>שלום <span style="big">עולם</span> טוב</p>',
            $this->style(),
            TextFormat::Markup,
        );
        $this->doc->addPage(new Dimensions(300.0, 100.0))->fitTextflow($flow, new Box(0.0, 0.0, 300.0, 100.0));
        $this->doc->close();
        $m = [];
        preg_match_all('#/F1 (\d+) Tf#', $this->out->contents(), $m);

        self::assertSame(['10', '20', '10'], $m[1] ?? null);
    }

    #[Test]
    public function wordsOfBothDirectionsShareALine(): void
    {
        $flow = $this->doc->textflow('<p>The word שלום means peace.</p>', $this->style(), TextFormat::Markup);
        $this->doc->addPage(new Dimensions(300.0, 100.0))->fitTextflow($flow, new Box(0.0, 0.0, 300.0, 100.0));

        self::assertSame([1, 0.0], [$flow->lastFit()?->lineCount, $flow->lastFit()?->firstBaseline[0]]);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(compress: false));
        $this->doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'));
    }

    private function style(): TextStyle
    {
        return new TextStyle(
            font: 'DejaVuSans',
            size: 10.0,
        );
    }
}
