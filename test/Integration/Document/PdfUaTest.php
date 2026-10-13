<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Document;

use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Color\IccProfile;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Accessibility;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Document\PdfA;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Import\PdfFile;
use Php2Pdf\Navigation\Destination;
use Php2Pdf\Structure\StructureRole;
use Php2Pdf\Table\Cell;
use Php2Pdf\Table\CellStyle;
use Php2Pdf\Table\Column;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\TextFormat;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tagged documents written to PDF/UA-1 (#35), validated by veraPDF: content tagged on the canvas
 * with a figure and links, a textflow over two pages with a heading and links, a table over two
 * pages, and PDF/A-2b alongside.
 */
#[Group('integration')]
#[Group('pdfa')]
final class PdfUaTest extends TestCase
{
    /** @return array<string, array{0: string, 1: list<string>}> the case, and the flavours veraPDF checks */
    public static function documentProvider(): array
    {
        return [
            'tagged on the canvas, with a figure and links' => ['canvas', ['ua1']],
            'a textflow over two pages'                     => ['textflow', ['ua1']],
            'a table over two pages'                        => ['table', ['ua1']],
            'with PDF/A-2b'                                 => ['pdfa', ['ua1', '2b']],
            'a tagged page imported and placed twice'       => ['imported', ['ua1']],
        ];
    }

    /** @param list<string> $flavours */
    #[Test]
    #[DataProvider('documentProvider')]
    public function aDocumentWrittenToPdfUaPassesVeraPdf(string $case, array $flavours): void
    {
        $path = PdfTools::outputDir() . "/pdfua-{$case}.pdf";
        $doc  = new Document($path, new DocumentOptions(
            clock: new FixedClock('2026-01-01T12:00:00Z'),
            pdfa: 'pdfa' === $case ? PdfA::A2b : null,
            accessibility: new Accessibility('en-GB', pdfUa: true),
        ));
        if ('pdfa' === $case) {
            $doc->setOutputIntent(
                IccProfile::fromFile(PdfTools::assetPath('icc/sRGB-v2-micro.icc')),
                'sRGB IEC61966-2.1',
            );
        }
        $doc->info()->setTitle("PDF/UA: {$case}");
        $font = $doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $doc->styles()->define('body', new TextStyle(
            font: $font,
            size: 11.0,
        ));
        $doc->styles()->define('heading', new TextStyle(
            size: 18.0,
            role: StructureRole::H1,
        ));
        match ($case) {
            'textflow' => $this->textflow($doc),
            'table'    => $this->table($doc),
            'imported' => $this->imported($doc),
            default    => $this->canvas($doc, $font),
        };
        $doc->close();
        PdfTools::assertValid($path);

        $failed = [];
        foreach ($flavours as $flavour) {
            foreach (PdfTools::veraPdf($path, $flavour) as $failure) {
                $failed[] = "{$flavour}: {$failure}";
            }
        }
        self::assertSame([], $failed);
    }

    /** A heading and a paragraph tagged on the canvas, a rule as an artifact, a figure, and links. */
    private function canvas(Document $doc, FontInterface $font): void
    {
        $page   = $doc->addPage(PageSize::A4);
        $canvas = $page->canvas();
        $canvas->tag(StructureRole::H1, static function (Canvas $canvas) use ($font): void {
            $canvas->drawText('A heading', $font, 20.0, 50.0, 780.0);
        });
        $canvas->fillRect(50.0, 770.0, 495.0, 1.0);
        $canvas->tag(StructureRole::Paragraph, static function (Canvas $canvas) use ($font): void {
            $canvas->drawText('A paragraph with a link.', $font, 11.0, 50.0, 750.0);
        });
        $page->addLink(new Box(50.0, 745.0, 120.0, 15.0), 'https://example.com/');
        $page->addLink(new Box(50.0, 725.0, 120.0, 15.0), Destination::at(1, 0.0, 842.0));
        $page->place(
            $doc->image(PdfTools::assetPath('images/rgb.jpg')),
            new Box(50.0, 400.0, 200.0, 200.0),
            Fit::meet(),
            'A photograph',
        );
    }

    /** A page of a tagged file, compressed with object streams, placed twice: its structure comes with the first. */
    private function imported(Document $doc): void
    {
        $out    = new MemoryOutput();
        $source = new Document($out, new DocumentOptions(accessibility: new Accessibility('en-GB', pdfUa: true)));
        $source->info()->setTitle('Source');
        $source->styles()->define('body', new TextStyle(
            font: $source->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf')),
            size: 11.0,
        ));
        $source->styles()->define('heading', new TextStyle(
            size: 18.0,
            role: StructureRole::H1,
        ));
        $source->addPage(PageSize::A4)->fitTextflow(
            $source->textflow(
                '<p style="heading">Imported</p><p>An imported paragraph.</p>',
                'body',
                TextFormat::Markup,
            ),
            new Box(50.0, 600.0, 400.0, 200.0),
        );
        $source->close();
        $page     = $doc->addPage(PageSize::A4);
        $imported = $doc->import(PdfFile::fromData($out->contents()))->page(1);
        $page->place($imported, new Box(50.0, 400.0, 250.0, 400.0), Fit::meet());
        $page->place($imported, new Box(300.0, 400.0, 250.0, 400.0), Fit::meet());
    }

    /** A rowed table with a header and footer, over two pages. */
    private function table(Document $doc): void
    {
        $table = $doc->table([Column::pt(150.0), Column::pt(100.0)], new CellStyle(), 'body');
        $table->addHeaderRow([Cell::text('Item'), Cell::text('Amount')]);
        for ($row = 1; $row <= 40; ++$row) {
            $table->addRow([Cell::text("Item {$row}"), Cell::text((string) ($row * 10))]);
        }
        $table->addFooterRow([Cell::text('Total')->spanning(2)]);
        do {
            $result = $doc->addPage(PageSize::A4)->fitTable($table, new Box(50.0, 100.0, 250.0, 500.0));
        } while (FlowResult::Done !== $result);
    }

    /** A heading and paragraphs with links, over two pages. */
    private function textflow(Document $doc): void
    {
        $flow = $doc->textflow(
            '<p style="heading">A heading</p><p>A paragraph with <a href="https://example.com/">a link long enough to '
                . 'break over lines</a>, and <u>underlined</u> text, long enough to run on to the next page.</p>'
                . '<p>Another paragraph.</p><ul><li>A point</li><li>Another, with<ol type="i"><li>a nested'
                . ' point</li></ol></li></ul>',
            'body',
            TextFormat::Markup,
        );
        $doc->addPage(PageSize::A4)->fitTextflow($flow, new Box(50.0, 700.0, 200.0, 80.0));
        $doc->addPage(PageSize::A4)->fitTextflow($flow, new Box(50.0, 400.0, 200.0, 400.0));
    }
}
