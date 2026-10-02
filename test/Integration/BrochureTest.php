<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Color\Gray;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Fit\Position;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Import\PageBox;
use Php2Pdf\Text\FlowOptions;
use Php2Pdf\Text\Hyphenation\Hyphenator;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\Leading;
use Php2Pdf\Text\Style\TabStop;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Style\VerticalAlignment;
use Php2Pdf\Text\TextFormat;
use Php2Pdf\Text\TextlineOptions;
use Php2PdfTest\TestAsset\PdfTools;
use Php2PdfTest\TestAsset\Visual;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;

/**
 * The Phase 7 exit: a two-column, multi-page brochure generated from a template and plain copy.
 */
#[Group('integration')]
final class BrochureTest extends TestCase
{
    #[Test]
    public function brochureFlowsAcrossColumnsAndPages(): void
    {
        $path = PdfTools::outputDir() . '/brochure.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'));
        $doc->styles()->define('body', new TextStyle(
            font: 'SourceSerif4-Regular',
            bold: 'SourceSerif4-Bold',
            size: 9.5,
            leading: Leading::pt(13.5),
            alignment: Alignment::Justify,
            hyphenate: true,
            language: 'en',
            spaceAfter: 6.0,
        ));
        $doc->styles()->define(
            'h2',
            new TextStyle(
                font: 'SourceSans3-Bold',
                size: 14.0,
                leading: Leading::pt(17.0),
                spaceBefore: 10.0,
                spaceAfter: 4.0,
                keepWithNext: true,
                alignment: Alignment::Left,
                color: Rgb::fromHex('#0088b8'),
            ),
            basedOn: 'body',
        );
        $doc->styles()->define(
            'table',
            new TextStyle(
                tabs: [TabStop::left(0.0), TabStop::decimal(150.0), TabStop::right(230.0)],
                alignment: Alignment::Left,
                hyphenate: false,
            ),
            basedOn: 'body',
        );

        $hyphenator = new Hyphenator([
            '1ty',
            'po1',
            'o1gra',
            'ra1ph',
            '1pre',
            'ci1sion',
            '1ci',
            'pa1ra',
            'ra1gr',
            'g1ra',
            '1do',
            'cu1me',
            '1ment',
            'tem1',
            '1pla',
        ], [
            'pre-ci-sion',
            'ty-pog-ra-phy',
            'para-graph',
            'docu-ment',
            'tem-plate',
            'tem-plates',
            'gen-er-a-tive',
            'posi-tioned',
            'trans-forms',
            'em-bed-ded',
            'hy-phen-ation',
        ]);
        $copy = (string) file_get_contents(PdfTools::assetPath('text/brochure.txt'));
        $flow = $doc->textflow($copy, 'body', TextFormat::Markup, ['en' => $hyphenator]);

        $template = $doc->import(PdfTools::assetPath('pdf/classic.pdf'))->page(1, PageBox::Trim);
        $pages    = 0;
        do {
            $page = $doc->addPage(PageSize::A4);
            ++$pages;
            // The imported page serves as artwork in the corner; the copy flows below it.
            $page->place($template, Box::fromMm(165.0, 273.0, 30.0, 20.0), Fit::meet(Position::topRight()));
            $page->canvas()
                ->setFillColor(Rgb::fromHex('#0088b8'))
                ->fillRect(
                    Box::fromMm(15.0, 275.0, 125.0, 1.2)->x,
                    Box::fromMm(15.0, 275.0, 125.0, 1.2)->y,
                    354.33,
                    3.4,
                );
            $result = $page->fitTextflow($flow, Box::fromMm(15.0, 20.0, 85.0, 250.0));
            if (FlowResult::BoxFull === $result) {
                $result = $page->fitTextflow($flow, Box::fromMm(110.0, 20.0, 85.0, 250.0));
            }
            $page->canvas()
                ->setFillColor(new Gray(0.4))
                ->fitTextline(
                    "Page {$pages}",
                    $doc->fonts()->find('SourceSans3-Regular'),
                    8.0,
                    Box::fromMm(15.0, 8.0, 180.0, 6.0),
                    TextlineOptions::at(Position::bottomCenter()),
                );
        } while (FlowResult::Done !== $result && $pages < 6);

        $footer = $doc->textflow('Copy fitted into a fixed box, vertically centred.', new TextStyle(
            font: 'SourceSans3-Regular',
            size: 20.0,
            alignment: Alignment::Center,
            color: Rgb::fromHex('#c2185b'),
        ));
        $page->canvas()
            ->setStrokeColor(Rgb::fromHex('#c2185b'))
            ->setLineWidth(0.5)
            ->box(Box::fromMm(110.0, 20.0, 85.0, 12.0))
            ->stroke();
        $page->fitTextflow($footer, Box::fromMm(110.0, 20.0, 85.0, 12.0), new FlowOptions(
            verticalAlignment: VerticalAlignment::Center,
            shrinkToFit: true,
            shrinkLimit: 0.4,
        ));
        $doc->close();

        self::assertSame(4, $pages);
        self::assertTrue($flow->isDone());
        $fit = $footer->lastFit();
        self::assertNotNull($fit);
        self::assertLessThan(1.0, $fit->scale);
        PdfTools::assertValid($path);
        self::assertStringContainsString('Generative flow', PdfTools::text($path, 1));
        for ($p = 1; $p <= $pages; ++$p) {
            Visual::assertMatchesBaseline($path, "brochure-p{$p}", $p);
        }
    }
}
