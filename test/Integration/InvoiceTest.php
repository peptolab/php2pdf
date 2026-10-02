<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Canvas\TextlineOptions;
use Php2Pdf\Color\Cmyk;
use Php2Pdf\Color\Gray;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\Page;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Fit\Position;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Table\Border;
use Php2Pdf\Table\Cell;
use Php2Pdf\Table\CellStyle;
use Php2Pdf\Table\Column;
use Php2Pdf\Table\Padding;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\Leading;
use Php2Pdf\Text\Style\TextStyle;
use Php2PdfTest\TestAsset\PdfTools;
use Php2PdfTest\TestAsset\Visual;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * An invoice of the kind usually built with PDFlib's table engine: textflow cells, fixed column
 * widths with column spans, per-cell margins, bottom-only rules, three repeated header rows,
 * top-left placement, and a box-full loop that re-places the page template. All names are
 * fictional.
 */
#[Group('integration')]
final class InvoiceTest extends TestCase
{
    private static function projectName(int $i): string
    {
        $names = [
            'Riverside House',
            'Civic Library Extension and Public Reading Room with a name long enough to wrap onto a second line',
            'Courtyard Studio',
            'Hillside Pavilion',
        ];

        return $names[$i % 4] ?? 'Project';
    }

    #[Test]
    public function invoiceFlowsOverTwoPages(): void
    {
        $path = PdfTools::outputDir() . '/invoice.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->info()->setTitle('Invoice #EX-2026-0042');
        $sans = $doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Regular.otf'));
        $bold = $doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Bold.otf'));
        $ink  = Cmyk::fromPercent(0.0, 0.0, 0.0, 100.0);

        // Styles are defined once by name; cells refer to them.
        $styles = $doc->styles();
        $styles->define('cell-title', new TextStyle(
            font: $sans,
            size: 7.0,
            leading: Leading::pt(11.0),
            color: $ink,
        ));
        $styles->define('cell-text', new TextStyle(
            font: $sans,
            size: 10.0,
            leading: Leading::pt(11.0),
            color: $ink,
        ));
        $styles->define('cell-text-sm', new TextStyle(
            font: $sans,
            size: 7.0,
            leading: Leading::pt(11.0),
            color: $ink,
        ));
        $styles->define('amount', new TextStyle(alignment: Alignment::Right), basedOn: 'cell-text');
        $styles->define('amount-title', new TextStyle(alignment: Alignment::Right), basedOn: 'cell-title');

        $rule   = new CellStyle(borderBottom: new Border(0.25, $ink));
        $margin = static fn(float $top, float $bottom): CellStyle => new CellStyle(padding: Padding::mm(
            $top,
            0.0,
            $bottom,
            0.0,
        ));

        // The branded page: a band, the title and the invoice number and date boxes.
        $template = $doc->template(Dimensions::mm(210.0, 297.0));
        $tc       = $template->canvas();
        $tc->setFillColor(Cmyk::fromPercent(100.0, 44.0, 0.0, 0.0))->fillRect(
            0.0,
            $template->height() - 60.0,
            $template->width(),
            60.0,
        );
        $tc->setFillColor(Gray::white())->fitTextline(
            'EXAMPLE DESIGN AWARDS',
            $bold,
            16.0,
            Box::fromMm(15.0, 277.0, 100.0, 15.0),
            TextlineOptions::at(Position::centerLeft()),
        );
        $tc->setFillColor($ink)->fitTextline(
            'TAX INVOICE',
            $bold,
            11.0,
            Box::fromMm(15.0, 250.0, 60.0, 8.0),
            TextlineOptions::at(Position::centerLeft()),
        );
        $tc->setFillColor($ink)
            ->setLineWidth(0.25)
            ->line(
                Box::fromMm(15.0, 248.0, 180.0, 0.0)->x,
                Box::fromMm(15.0, 248.0, 180.0, 0.0)->y,
                Box::fromMm(195.0, 248.0, 0.0, 0.0)->x,
                Box::fromMm(15.0, 248.0, 180.0, 0.0)->y,
            )
            ->stroke();

        $entries = [];
        for ($i = 1; $i <= 20; ++$i) {
            $entries[] = [sprintf('EX-%04d', 1000 + $i), self::projectName($i), 495.0];
        }
        $total = 495.0 * 20;

        $table = $doc->table([Column::mm(55.0), Column::auto(), Column::mm(30.0), Column::mm(20.0)], $margin(
            top: 0.0,
            bottom: 3.0,
        ));
        $table->addHeaderRow([
            Cell::text('CLIENT', 'cell-title'),
            Cell::text('Alex Example', 'cell-text')->spanning(3),
        ]);
        $table->addHeaderRow([
            Cell::text('COMPANY', 'cell-title'),
            Cell::text(
                "Example Architects Ltd\n1 Sample Street\nSpringfield 0000",
                'cell-text',
            )->spanning(3),
        ]);
        $table->addHeaderRow(
            [
                Cell::text('REFERENCE', 'cell-title'),
                Cell::text('SUBMISSION', 'cell-title')->spanning(2),
                Cell::text('PRICE', 'amount-title'),
            ],
            $margin(
                top: 6.0,
                bottom: 3.0,
            )->merge($rule),
        );
        foreach ($entries as [$reference, $name, $price]) {
            $table->addRow(
                [
                    Cell::text($reference, 'cell-text-sm'),
                    Cell::text($name, 'cell-text')->spanning(2),
                    Cell::text(sprintf('$%0.2f', $price), 'amount'),
                ],
                $margin(
                    top: 3.0,
                    bottom: 3.0,
                )->merge($rule),
            );
        }
        $table->addRow(
            [null, null, Cell::text('TAX 10%', 'cell-title'), Cell::text(sprintf('$%0.2f', $total / 11), 'amount')],
            $margin(
                top: 6.0,
                bottom: 3.0,
            )->merge($rule),
            1,
        );
        $table->addRow(
            [null, null, Cell::text('TOTAL PRICE', 'cell-title'), Cell::text(sprintf('$%0.2f', $total), 'amount')],
            $margin(
                top: 3.0,
                bottom: 3.0,
            )->merge($rule),
            1,
        );

        $pages = $doc->flow(
            $table,
            Box::fromMm(15.0, 48.0, 180.0, 176.0),
            static function (Document $doc, int $number) use ($template, $sans, $styles): Page {
                $page = $doc->addPage(PageSize::A4);
                $page->place($template, $page->mediaBox(), Fit::entire());
                $page->fitTextflow($doc->textflow('#EX-2026-0042', 'cell-text'), Box::fromMm(70.0, 234.0, 65.0, 5.0));
                $page->fitTextflow($doc->textflow('DATE', 'cell-title'), Box::fromMm(150.0, 234.0, 15.0, 5.0));
                $page->fitTextflow($doc->textflow('1 January 2026', 'cell-text'), Box::fromMm(165.0, 234.0, 35.0, 5.0));
                $page->fitTextline(
                    "Page {$number}",
                    $sans,
                    7.0,
                    Box::fromMm(15.0, 10.0, 180.0, 5.0),
                    TextlineOptions::at(Position::center()),
                );
                unset($styles);

                return $page;
            },
        );
        $doc->close();

        PdfTools::assertValid($path);
        self::assertSame(2, $pages);
        self::assertSame('2', PdfTools::info($path)['Pages'] ?? null);
        self::assertSame(FlowResult::Done, $table->lastFit()?->result);
        $text = PdfTools::text($path, 1) . PdfTools::text($path, 2);
        self::assertStringContainsString('SUBMISSION', PdfTools::text($path, 2), 'Header rows repeat on page 2.');
        self::assertStringContainsString('$9900.00', $text);
        self::assertStringContainsString('$900.00', $text);
        Visual::assertMatchesBaseline($path, 'invoice-p1', 1);
        Visual::assertMatchesBaseline($path, 'invoice-p2', 2);
    }
}
