<?php

declare(strict_types=1);

namespace PhpPdfTest\Integration;

use PhpPdf\Color\Gray;
use PhpPdf\Color\Rgb;
use PhpPdf\Core\FixedClock;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Fit\Fit;
use PhpPdf\Flow\FlowResult;
use PhpPdf\Geometry\Box;
use PhpPdf\Table\Border;
use PhpPdf\Table\Cell;
use PhpPdf\Table\CellStyle;
use PhpPdf\Table\Column;
use PhpPdf\Table\FooterContext;
use PhpPdf\Table\Padding;
use PhpPdf\Table\Table;
use PhpPdf\Text\Style\Alignment;
use PhpPdf\Text\Style\Leading;
use PhpPdf\Text\Style\TextStyle;
use PhpPdf\Text\Style\VerticalAlignment;
use PhpPdfTest\Support\PdfTools;
use PhpPdfTest\Support\Visual;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_slice;
use function array_sum;
use function preg_match;
use function str_contains;
use function str_repeat;

/**
 * A table sheet on a top-down page: spans, image and SVG cells, backgrounds, borders, vertical
 * alignment, markup cells, auto columns, and a row that splits across two boxes.
 */
final class TableSheetTest extends TestCase
{
    /** Each page of a long invoice ends with its subtotal, carried forward until the total (#23). */
    #[Test]
    public function footersCarrySubtotalsForward(): void
    {
        $path = PdfTools::outputDir() . '/table-footers.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $text = new TextStyle(
            font: $doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Regular.otf')),
            size: 10.0,
        );
        $table = new Table([Column::auto(), Column::pt(80.0)], new CellStyle(padding: Padding::all(3.0)), $text);
        $table->addHeaderRow(['Item', 'Amount']);
        $amounts = [];
        for ($i = 1; $i <= 60; ++$i) {
            $amounts[] = $i;
            $table->addRow(["Item {$i}", "{$i}.00"]);
        }
        $table->addFooterRow(['Carried forward', '0.00']);
        $table->onFooter(static function (FooterContext $context) use ($amounts): array {
            $sum = array_sum(array_slice($amounts, offset: 0, length: $context->lastRow + 1));

            return [[$context->isLastBox ? 'Total' : 'Carried forward', "{$sum}.00"]];
        });
        $pages = 0;
        do {
            $result = $doc->addPage(PageSize::A5)->fitTable($table, Box::fromMm(15, 15, 118, 180));
            ++$pages;
        } while (FlowResult::Done !== $result && $pages < 10);
        $doc->close();

        PdfTools::assertValid($path);
        $first = PdfTools::text($path, 1);
        $last  = PdfTools::text($path, $pages);
        self::assertSame(
            [true, true, false],
            [
                (bool) preg_match('/Carried forward\s+\d+\.00/', $first),
                str_contains($last, 'Total') && str_contains($last, '1830.00'),
                str_contains($first, 'Total'),
            ],
        );
    }

    #[Test]
    public function tableSheetMatchesBaseline(): void
    {
        $path = PdfTools::outputDir() . '/table-sheet.pdf';
        $doc  = new Document($path, new DocumentOptions(
            clock: new FixedClock('2026-01-01T12:00:00Z'),
            topDown: true,
        ));
        $sans = $doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Regular.otf'));
        $bold = $doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Bold.otf'));
        $doc->styles()->define('body', new TextStyle(
            font: $sans,
            size: 9.0,
            leading: Leading::pt(11.0),
            bold: $bold,
        ));
        $doc->styles()->define(
            'head',
            new TextStyle(
                font: $bold,
                size: 9.0,
                color: Gray::white(),
            ),
            basedOn: 'body',
        );
        $photo = $doc->image(PdfTools::assetPath('images/rgb.jpg'));
        $art   = $doc->svg(PdfTools::assetPath('svg/shapes.svg'));

        $grid = new CellStyle(
            padding: Padding::all(4.0),
            border: new Border(0.5, new Gray(0.6)),
        );
        $table = new Table(
            [
                Column::mm(30.0),
                Column::auto(),
                Column::percent(20.0),
                Column::mm(35.0, new CellStyle(alignment: Alignment::Right)),
            ],
            $grid,
            'body',
        );
        $table->addHeaderRow(['Image', 'Description', 'Status', 'Amount'], new CellStyle(
            background: Rgb::fromHex('#0088b8'),
            text: 'head',
        ));
        $table->addRow([
            Cell::of($photo),
            Cell::markup(
                'A photo cell with <b>bold</b> markup and enough words to wrap over several lines inside an auto-sized column.',
            ),
            Cell::text('Centred', null, new CellStyle(
                verticalAlignment: VerticalAlignment::Center,
                alignment: Alignment::Center,
                background: Rgb::fromHex('#e6f3f8'),
            )),
            '$1,200.00',
        ]);
        $table->addRow([
            Cell::of($art, Fit::meet()),
            Cell::text(
                'Artwork converted from SVG, bottom aligned.',
                null,
                new CellStyle(verticalAlignment: VerticalAlignment::Bottom),
            ),
            Cell::text('Spans two rows', null, new CellStyle(verticalAlignment: VerticalAlignment::Center))->spanning(
                1,
                2,
            ),
            '$80.00',
        ]);
        $table->addRow([Cell::text('A cell spanning two columns')->spanning(2), '$0.00']);
        $table->addRow([
            Cell::text('Totals')
                ->spanning(3, 1)
                ->styled(new CellStyle(
                    alignment: Alignment::Right,
                    background: new Gray(0.92),
                )),
            Cell::text('$1,280.00', new TextStyle(font: $bold)),
        ]);
        for ($i = 1; $i <= 6; ++$i) {
            $table->addRow(
                ["Line {$i}", str_repeat('Filler text that wraps. ', times: $i * 3), 'ok', '$1.00'],
                null,
                $i > 3 ? 7 : null,
            );
        }

        $page   = $doc->addPage(PageSize::A4);
        $result = $page->fitTable($table, Box::fromMm(15.0, 20.0, 180.0, 120.0));
        self::assertSame(FlowResult::BoxFull, $result);
        $info = $table->lastFit();
        self::assertNotNull($info);
        $bottom = $info->bottom();
        self::assertNotNull($bottom);
        $page->canvas()
            ->setStrokeColor(Rgb::fromHex('#c2185b'))
            ->setLineWidth(1.0)
            ->line($info->columnEdges[0] ?? 0.0, $bottom + 4.0, $info->columnEdges[4] ?? 0.0, $bottom + 4.0)
            ->stroke();
        $result = $page->fitTable($table, Box::fromMm(15.0, 150.0, 180.0, 130.0));
        self::assertSame(FlowResult::Done, $result);
        $doc->close();

        PdfTools::assertValid($path);
        self::assertStringContainsString('Spans two rows', PdfTools::text($path));
        Visual::assertMatchesBaseline($path, 'table-sheet');
    }
}
