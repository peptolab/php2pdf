<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text\Flow;

use Override;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Text\Style\Leading;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Textflow;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Lines of 10pt DejaVu Sans on 12pt leading: the first fills 11.645pt of a box, the second
 * 23.645pt and the third 35.645pt.
 */
#[Group('integration')]
final class LayoutTest extends TestCase
{
    private const float TWO_LINES = 30.0;

    private Document $doc;

    #[Test]
    public function aHeadingKeptWithAnEmptyParagraphStaysInTheBox(): void
    {
        $flow = $this->doc
            ->textflowBuilder('body')
            ->paragraph()
            ->text('Intro')
            ->paragraph('heading')
            ->text('Heading')
            ->paragraph()
            ->text('')
            ->build();

        self::assertSame(
            [FlowResult::Done, 2],
            [$this->fit($flow), $flow->lastFit()?->lineCount],
        );
    }

    #[Test]
    public function aHeadingKeptWithNothingAfterItStaysInTheBox(): void
    {
        $flow = $this->doc
            ->textflowBuilder('body')
            ->paragraph()
            ->text('Intro')
            ->paragraph('heading')
            ->text('Heading')
            ->build();

        self::assertSame(
            [FlowResult::Done, 2],
            [$this->fit($flow), $flow->lastFit()?->lineCount],
        );
    }

    #[Test]
    public function aHeadingMovesToTheNextBoxWhenTheParagraphAfterItCannotStart(): void
    {
        $flow = $this->doc
            ->textflowBuilder('body')
            ->paragraph()
            ->text('Intro')
            ->paragraph('heading')
            ->text('Heading')
            ->paragraph()
            ->text('Body')
            ->build();

        self::assertSame(
            [FlowResult::BoxFull, 1, "Heading\n\nBody"],
            [$this->fit($flow), $flow->lastFit()?->lineCount, $flow->remainingText()],
        );
    }

    #[Test]
    public function aMovedHeadingLeavesTheBoxAsTallAsTheLinesBeforeIt(): void
    {
        $flow = $this->doc
            ->textflowBuilder('body')
            ->paragraph()
            ->text('Intro')
            ->paragraph('heading')
            ->text('Heading')
            ->paragraph()
            ->text('Body')
            ->build();
        $this->fit($flow);
        $font = $this->doc->fonts()->find('DejaVuSans');

        self::assertEqualsWithDelta(
            $font->ascender(10.0) - $font->descender(10.0),
            $flow->lastFit()->height ?? 0.0,
            1.0e-9,
        );
    }

    #[Test]
    public function aParagraphWithRoomForFewerLinesThanItsOrphansMovesWhole(): void
    {
        $flow = $this->doc
            ->textflowBuilder('body')
            ->paragraph()
            ->text('Intro')
            ->paragraph()
            ->text(str_repeat('The quick brown fox jumps over the lazy dog. ', times: 6))
            ->build();

        self::assertSame(
            [FlowResult::BoxFull, 1],
            [$this->fit($flow), $flow->lastFit()?->lineCount],
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->doc = new Document(
            new MemoryOutput(),
            new DocumentOptions(
                clock: new FixedClock('2026-01-01'),
                compress: false,
                xmp: false,
            ),
        );
        $this->doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'));
        $this->doc->styles()->define('body', new TextStyle(
            font: 'DejaVuSans',
            size: 10.0,
            leading: Leading::pt(12.0),
        ));
        $this->doc->styles()->define('heading', new TextStyle(keepWithNext: true), basedOn: 'body');
    }

    private function fit(Textflow $flow): FlowResult
    {
        return $this->doc->addPage()->fitTextflow($flow, new Box(0.0, 0.0, 200.0, self::TWO_LINES));
    }
}
