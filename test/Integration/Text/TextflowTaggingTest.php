<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Structure\StructureRole;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\TextFormat;
use Php2PdfTest\TestAsset\PdfTools;
use Php2PdfTest\TestAsset\TaggedDocument;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function preg_match_all;

/** A textflow's paragraphs and links tagged on a tagged page (#35). */
#[Group('integration')]
final class TextflowTaggingTest extends TestCase
{
    private Document $doc;
    private MemoryOutput $out;

    #[Test]
    public function aLinkBrokenAcrossLinesIsOneLinkWithAnAnnotationForEachLine(): void
    {
        $pdf = $this->fitted(
            'Read <a href="https://example.com/">the whole of this linked text</a> now.',
            new Box(50.0, 700.0, 80.0, 100.0),
        );

        self::assertSame(
            [1, 1],
            [
                TaggedDocument::count($pdf, 'Link'),
                preg_match('#/S/Link/P \d+ 0 R/Pg \d+ 0 R/K\[\d+ <</Type/OBJR[^>]+>>( <</Type/OBJR[^>]+>>)+\]#', $pdf),
            ],
        );
    }

    #[Test]
    public function aParagraphBrokenAcrossBoxesStaysOneElement(): void
    {
        $flow = $this->doc->textflow('One paragraph long enough to need several lines in a narrow box.', 'body');
        $this->doc->addPage()->fitTextflow($flow, new Box(50.0, 700.0, 80.0, 30.0));
        $this->doc->addPage()->fitTextflow($flow, new Box(50.0, 700.0, 80.0, 300.0));
        $this->doc->close();
        $pdf = $this->out->contents();

        self::assertSame(
            [1, 1],
            [TaggedDocument::count($pdf, 'P'), preg_match('#/S/P/P \d+ 0 R/Pg \d+ 0 R/K\[0 <</Type/MCR#', $pdf)],
        );
    }

    #[Test]
    public function aResetFlowIsTaggedAfresh(): void
    {
        $flow = $this->doc->textflow('Drawn twice.', 'body');
        $page = $this->doc->addPage();
        $page->fitTextflow($flow, new Box(50.0, 700.0, 200.0, 100.0));
        $flow->reset();
        $page->fitTextflow($flow, new Box(50.0, 500.0, 200.0, 100.0));
        $this->doc->close();

        self::assertSame(2, TaggedDocument::count($this->out->contents(), 'P'));
    }

    #[Test]
    public function linksToDifferentTargetsAreDifferentElements(): void
    {
        $pdf = $this->fitted(
            '<a href="https://example.com/">One</a><a href="https://example.org/">two</a> and <a href="https://example.com/">one</a>',
            new Box(50.0, 700.0, 400.0, 100.0),
        );

        self::assertSame(3, TaggedDocument::count($pdf, 'Link'));
    }

    #[Test]
    public function listsAreTaggedAsListsOfItemsWithLabelsAndBodies(): void
    {
        $pdf = $this->fitted(
            '<ul><li>One</li><li>Two<ol><li>Inner</li></ol></li></ul>',
            new Box(50.0, 700.0, 400.0, 100.0),
        );

        self::assertSame(
            [2, 3, 3, 3, 1, 1, 1],
            [
                TaggedDocument::count($pdf, 'L'),
                TaggedDocument::count($pdf, 'LI'),
                TaggedDocument::count($pdf, 'Lbl'),
                TaggedDocument::count($pdf, 'LBody'),
                preg_match('#/S/L/P \d+ 0 R/K\[\d+ 0 R \d+ 0 R\]/A<</O/List/ListNumbering/Disc>>#', $pdf),
                preg_match('#/S/L/P \d+ 0 R/K\[\d+ 0 R\]/A<</O/List/ListNumbering/Decimal>>#', $pdf),
                preg_match('#/S/Lbl/P (\d+) 0 R/Pg \d+ 0 R/K\[0\]>>.*?/S/LBody/P \1 0 R/K\[(\d+) 0 R\]>>#s', $pdf),
            ],
        );
    }

    #[Test]
    public function paragraphsAreTaggedAsTheirStylesSay(): void
    {
        $pdf = $this->fitted(
            '<p style="heading">A heading</p><p>A paragraph.</p>',
            new Box(50.0, 700.0, 400.0, 100.0),
        );

        self::assertSame(
            ['Artifact', 'EMC', '/H1 0', 'EMC', '/P 1', 'EMC', 'Artifact', 'EMC'],
            TaggedDocument::marks($pdf),
        );
    }

    #[Test]
    public function taggedTextIsGroupedInOneTextObjectWithItsSequencesInside(): void
    {
        $pdf = $this->fitted(
            '<p style="heading">A heading</p><p>A paragraph with <a href="https://example.com/">a link</a>.</p>',
            new Box(50.0, 700.0, 400.0, 100.0),
        );

        self::assertSame(
            [1, 1],
            [
                preg_match_all('#\bBT\b#', $pdf),
                preg_match(
                    "#\nBT\n/H1 <</MCID 0>> BDC\n[^\n]+\nEMC\n/P <</MCID 1>> BDC\n(?:[^\n]+\n)+?EMC\nET\n#",
                    $pdf,
                ),
            ],
        );
    }

    #[Test]
    public function underlinesAndInlineArtworkAreArtifacts(): void
    {
        $pdf = $this->fitted(
            'An <img src="icon"> icon and <u>underlined</u> text.',
            new Box(50.0, 700.0, 400.0, 100.0),
        );

        self::assertSame(
            [['Artifact', 'EMC', '/P 0', 'EMC', 'Artifact', 'EMC'], 1, 1],
            [
                TaggedDocument::marks($pdf),
                preg_match("#/Artifact BMC\nq\nq\nq [\\d. ]+ cm /X\\d+ Do Q\nQ\nEMC\nBT\n/P <<#", $pdf),
                preg_match("#EMC\nET\n/Artifact BMC\n[\\d. ]+ re\nf\nQ\nEMC\n#", $pdf),
            ],
        );
    }

    #[Test]
    public function untaggedTextIsGroupedInOneTextObject(): void
    {
        $out  = new MemoryOutput();
        $doc  = new Document($out, new DocumentOptions(compress: false));
        $font = $doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $doc->addPage()->fitTextflow(
            $doc->textflow("One.\nTwo.", new TextStyle(font: $font)),
            new Box(50.0, 700.0, 200.0, 100.0),
        );
        $doc->close();

        self::assertSame(1, preg_match_all('#\bBT\b#', $out->contents()));
    }

    #[Override]
    protected function setUp(): void
    {
        [$this->doc, $this->out] = TaggedDocument::open();
        $font = $this->doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $this->doc->styles()->define('body', new TextStyle(
            font: $font,
            size: 10.0,
        ));
        $this->doc->styles()->define('heading', new TextStyle(
            size: 16.0,
            role: StructureRole::H1,
        ));
        $icon = $this->doc->template(new Dimensions(10.0, 10.0));
        $icon->canvas()->fillRect(0.0, 0.0, 10.0, 10.0);
        $this->doc->inlines()->define('icon', $icon);
    }

    private function fitted(string $markup, Box $box): string
    {
        $this->doc->addPage()->fitTextflow($this->doc->textflow($markup, 'body', TextFormat::Markup), $box);
        $this->doc->close();

        return $this->out->contents();
    }
}
