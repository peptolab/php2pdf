<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text\Flow;

use Closure;
use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Text\Flow\TextflowBuilder;
use Php2Pdf\Text\Style\TabStop;
use Php2Pdf\Text\Style\TextStyle;
use Php2PdfTest\TestAsset\PdfTools;
use Php2PdfTest\TestAsset\TextShows;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class TextflowBuilderTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;

    /** @return array<string, array{0: Closure(TextflowBuilder): TextflowBuilder, 1: FlowResult}> */
    public static function breakProvider(): array
    {
        return [
            'column break' => [
                static fn(TextflowBuilder $b): TextflowBuilder => $b->columnBreak(),
                FlowResult::BoxFull,
            ],
            'page break'   => [static fn(TextflowBuilder $b): TextflowBuilder => $b->pageBreak(), FlowResult::NextPage],
        ];
    }

    /** @param Closure(TextflowBuilder): TextflowBuilder $break */
    #[Test]
    #[DataProvider('breakProvider')]
    public function aBreakEndsTheBox(Closure $break, FlowResult $result): void
    {
        $flow = $break($this->doc->textflowBuilder($this->style())->text('Before'))->text('after')->build();

        self::assertSame($result, $this->doc->addPage()->fitTextflow($flow, new Box(50.0, 500.0, 200.0, 100.0)));
    }

    #[Test]
    public function aTabMovesTheTextAfterItToTheStop(): void
    {
        $style = $this->style()->merge(new TextStyle(tabs: [TabStop::left(100.0)]));
        $flow  = $this->doc
            ->textflowBuilder($style)
            ->text('a')
            ->tab()
            ->text('b')
            ->build();
        $this->doc->addPage()->fitTextflow($flow, new Box(0.0, 0.0, 300.0, 100.0));
        $this->doc->close();

        self::assertContains(100.0, TextShows::xs($this->out->contents()));
    }

    #[Test]
    public function italicTextTakesTheItalicFace(): void
    {
        $flow = $this->doc->textflowBuilder($this->style())->italic('x')->build();

        self::assertSame($this->doc->fonts()->find('SourceSans3-Regular')->width('x', 10.0), $flow->maxContentWidth());
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
            italic: 'SourceSans3-Regular',
            size: 10.0,
        );
    }
}
