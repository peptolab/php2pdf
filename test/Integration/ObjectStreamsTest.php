<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function str_contains;

/**
 * A document written with object streams and a cross-reference stream (#38) is valid, reads back
 * in poppler and in the library's own importer; uncompressed output and PDF before 1.5 keep the
 * cross-reference table.
 */
#[Group('integration')]
final class ObjectStreamsTest extends TestCase
{
    /** @return array<string, array{0: DocumentOptions, 1: bool}> the options, and whether objects go in object streams */
    public static function optionsProvider(): array
    {
        $clock = new FixedClock('2026-01-01T12:00:00Z');

        return [
            'by default'                 => [new DocumentOptions(clock: $clock), true],
            'turned off'                 => [
                new DocumentOptions(
                    clock: $clock,
                    objectStreams: false,
                ),
                false,
            ],
            'uncompressed'               => [
                new DocumentOptions(
                    clock: $clock,
                    compress: false,
                ),
                false,
            ],
            'PDF 1.4, before they exist' => [
                new DocumentOptions(
                    version: '1.4',
                    clock: $clock,
                ),
                false,
            ],
        ];
    }

    #[Test]
    public function aDocumentWithObjectStreamsImportsBack(): void
    {
        $source = $this->write('source', new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $path   = PdfTools::outputDir() . '/object-streams-imported.pdf';
        $doc    = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $import = $doc->import($source);
        $doc->addPage(PageSize::A4)->place($import->page(3), new Box(0.0, 0.0, 595.0, 842.0), Fit::meet());
        $doc->close();
        PdfTools::assertValid($path);

        self::assertSame(
            [3, true],
            [$import->pageCount(), str_contains(PdfTools::text($path), 'Object streams, page 3')],
        );
    }

    #[Test]
    public function aDocumentWithObjectStreamsReadsBackInPoppler(): void
    {
        $path = $this->write('poppler', new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));

        self::assertStringContainsString('Object streams, page 3', PdfTools::text($path, page: 3));
    }

    #[Test]
    #[DataProvider('optionsProvider')]
    public function objectStreamsAreWrittenWhereTheOptionsAllowThem(DocumentOptions $options, bool $packed): void
    {
        $pdf = (string) file_get_contents($this->write('options', $options));

        self::assertSame(
            [$packed, $packed, ! $packed],
            [str_contains($pdf, '/Type/ObjStm'), str_contains($pdf, '/Type/XRef'), str_contains($pdf, "\nxref\n0 ")],
        );
    }

    /** Three pages of text in an embedded font, enough objects for an object stream, checked by qpdf. */
    private function write(string $name, DocumentOptions $options): string
    {
        $path = PdfTools::outputDir() . "/object-streams-{$name}.pdf";
        $doc  = new Document($path, $options);
        $font = $doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        for ($n = 1; $n <= 3; ++$n) {
            $doc->addPage(PageSize::A4)->canvas()->drawText("Object streams, page {$n}", $font, 12.0, 72.0, 720.0);
        }
        $doc->close();
        PdfTools::assertValid($path);

        return $path;
    }
}
