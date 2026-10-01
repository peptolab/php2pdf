<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Destination;
use Php2Pdf\Document\Dimensions;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Textflow;
use Php2Pdf\Text\TextFormat;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function preg_match_all;
use function preg_replace_callback;
use function round;

/**
 * Links and match areas over Textflow text (#37). DejaVu Sans at 10 pt rises 9.28 above the
 * baseline and falls 2.36 below it; "Go " is 17.05 wide, "there" 26.45 and "there and" 48.45.
 */
#[Group('unit')]
final class TextLinkTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;

    /** @return array<string, array{0: string, 1: string}> */
    public static function targetProvider(): array
    {
        return [
            'a URL with a tooltip'    => [
                '<a href="https://example.com/" title="Example">there</a>',
                '/Contents(Example)/A<</S/URI/URI(https://example.com/)>>',
            ],
            'a named destination'     => ['<a href="#notes">there</a>', '/Dest(notes)'],
            'entities in the address' => [
                '<a href="https://example.com/?a=1&amp;b=2">there</a>',
                '/A<</S/URI/URI(https://example.com/?a=1&b=2)>>',
            ],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function unmarkedProvider(): array
    {
        return [
            'an anchor without href' => ['<a name="x">there</a>'],
            'an empty href'          => ['<a href="">there</a>'],
        ];
    }

    private static function rounded(string $pdf): string
    {
        return (string) preg_replace_callback(
            '#\d+\.\d{3,}#',
            static fn(array $m): string => (string) round((float) $m[0], precision: 2),
            $pdf,
        );
    }

    #[Test]
    public function aLinkOverSeveralLinesHasAnAreaOnEach(): void
    {
        $pdf = $this->fit(
            '<p><a href="https://example.com/">one two three four five six seven eight nine ten</a></p>',
            60.0,
        );

        self::assertGreaterThan(2, preg_match_all('#/URI\(https://example\.com/\)#', $pdf));
    }

    #[Test]
    #[DataProvider('targetProvider')]
    public function anAnchorLinksItsText(string $anchor, string $action): void
    {
        $pdf = $this->fit("<p>Go {$anchor}</p>");

        self::assertStringContainsString(
            "/Subtype/Link/Rect[17.05 88.36 43.5 100]/Border[0 0 0]{$action}",
            self::rounded($pdf),
        );
    }

    #[Test]
    #[DataProvider('unmarkedProvider')]
    public function anAnchorWithoutAnAddressLinksNothing(string $anchor): void
    {
        self::assertStringNotContainsString('/Subtype/Link', $this->fit("<p>Go {$anchor}</p>"));
    }

    #[Test]
    public function linksFollowTheCanvasTransformation(): void
    {
        $flow = $this->doc->textflow(
            '<p>Go <a href="https://example.com/">there</a></p>',
            $this->style(),
            TextFormat::Markup,
        );
        $page = $this->doc->addPage(new Dimensions(200.0, 100.0), topDown: true);
        $page->canvas()->translate(10.0, 5.0);
        $page->fitTextflow($flow, new Box(0.0, 0.0, 200.0, 100.0));
        $this->doc->close();

        self::assertStringContainsString('/Rect[27.05 83.36 53.5 95]', self::rounded($this->out->contents()));
    }

    #[Test]
    public function matchedTextReportsAnAreaPerLine(): void
    {
        $flow = Textflow::build($this->doc, $this->style())
            ->text('Go ')
            ->match('m', 'there and back again by the long road')
            ->build();
        $this->doc->addPage(new Dimensions(200.0, 100.0))->fitTextflow($flow, new Box(0.0, 0.0, 80.0, 100.0));

        $boxes = $flow->lastFit()?->matchboxes['m'] ?? [];
        self::assertSame(
            [[17.05, 88.36, 48.45, 11.64], [0.0, 76.36]],
            [
                array_map(static fn(float $v): float => round($v, precision: 2), [
                    $boxes[0]->x ?? 0.0,
                    $boxes[0]->y ?? 0.0,
                    $boxes[0]->width ?? 0.0,
                    $boxes[0]->height ?? 0.0,
                ]),
                [round($boxes[1]->x ?? -1.0, precision: 2), round($boxes[1]->y ?? -1.0, precision: 2)],
            ],
        );
    }

    #[Test]
    public function stylesInsideALinkShareItsArea(): void
    {
        $pdf = $this->fit('<p>Go <a href="https://example.com/">th<b>er</b>e</a></p>');

        self::assertSame(1, preg_match_all('#/Subtype/Link#', $pdf));
    }

    #[Test]
    public function textInArtworkReportsAreasButCarriesNoLinks(): void
    {
        $flow = $this->doc->textflow(
            '<p><a href="https://example.com/"><span match="m">there</span></a></p>',
            $this->style(),
            TextFormat::Markup,
        );
        $template = $this->doc->template(new Dimensions(200.0, 100.0));
        $template->canvas()->fitTextflow($flow, new Box(0.0, 0.0, 200.0, 100.0));
        $this->doc->addPage()->place($template, new Box(0.0, 0.0, 200.0, 100.0));
        $this->doc->close();

        self::assertSame(
            [0, 1],
            [
                preg_match_all('#/Subtype/Link#', $this->out->contents()),
                count($flow->lastFit()?->matchboxes['m'] ?? []),
            ],
        );
    }

    #[Test]
    public function theBuilderLinksToADestination(): void
    {
        $flow = Textflow::build($this->doc, $this->style())->text('Go ')->link(Destination::page(1), 'there')->build();
        $this->doc->addPage(new Dimensions(200.0, 100.0))->fitTextflow($flow, new Box(0.0, 0.0, 200.0, 100.0));
        $this->doc->close();

        self::assertSame(1, preg_match_all(
            '#/Subtype/Link/Rect\[[^]]+\]/Border\[0 0 0\]/Dest\[\d+ 0 R /Fit\]#',
            $this->out->contents(),
        ));
    }

    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(compress: false));
        $this->doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'));
    }

    private function fit(string $markup, float $width = 200.0): string
    {
        $flow = $this->doc->textflow($markup, $this->style(), TextFormat::Markup);
        $this->doc->addPage(new Dimensions(200.0, 100.0))->fitTextflow($flow, new Box(0.0, 0.0, $width, 100.0));
        $this->doc->addDestination('notes', Destination::page(1));
        $this->doc->close();

        return $this->out->contents();
    }

    private function style(): TextStyle
    {
        return new TextStyle(
            font: 'DejaVuSans',
            bold: 'DejaVuSans-Bold',
            size: 10.0,
        );
    }
}
