<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Exception;

use Closure;
use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Document\PdfX;
use PhpPdf\Exception\ConformanceException;
use PhpPdf\Exception\LayoutException;
use PhpPdf\Exception\ParseException;
use PhpPdf\Exception\PdfException;
use PhpPdf\Geometry\Box;
use PhpPdf\Image\ImageReader;
use PhpPdf\Import\PdfFile;
use PhpPdf\Svg\SvgFile;
use PhpPdf\Table\Column;
use PhpPdf\Table\Table;
use PhpPdf\Text\Style\TextStyle;
use PhpPdf\Text\TextFormat;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Failures throw a PdfException subtype that says what kind of problem it is (#28). */
#[Group('unit')]
final class ExceptionTypeTest extends TestCase
{
    /** @return array<string, array{0: Closure(): mixed, 1: class-string<PdfException>}> */
    public static function failureProvider(): array
    {
        $doc   = static fn(?DocumentOptions $options = null): Document => new Document(new MemoryOutput(), $options);
        $style = static fn(Document $d): TextStyle => new TextStyle(font: $d->fonts()->load(PdfTools::assetPath(
            'fonts/DejaVuSans.ttf',
        )));

        return [
            'a file that is not a PDF'         => [
                static fn() => PdfFile::fromData('not a pdf'),
                ParseException::class,
            ],
            'a truncated PNG'                  => [
                static fn() => ImageReader::read("\x89PNG\r\n\x1A\n"),
                ParseException::class,
            ],
            'data that is not SVG'             => [
                static fn() => SvgFile::fromData('plain text'),
                ParseException::class,
            ],
            'an unknown tag in strict markup'  => [
                static function () use ($doc, $style): void {
                    $d = $doc();
                    $d->textflow('<blink>x</blink>', $style($d), TextFormat::StrictMarkup);
                },
                ParseException::class,
            ],
            'a PDF/X document without a title' => [
                static function () use ($doc): void {
                    $d = $doc(new DocumentOptions(pdfx: PdfX::X4));
                    $d->addPage(PageSize::A4);
                    $d->close();
                },
                ConformanceException::class,
            ],
            'a frame too small for anything'   => [
                static function () use ($doc, $style): void {
                    $d = $doc();
                    $d->flow(
                        $d->textflow('Text', $style($d)),
                        new Box(0.0, 0.0, 100.0, 1.0),
                        static fn(Document $d) => $d->addPage(PageSize::A4),
                    );
                },
                LayoutException::class,
            ],
            'columns wider than the table'     => [
                static function () use ($doc): void {
                    $d     = $doc();
                    $table = new Table([Column::pt(300.0)]);
                    $table->addRow(['x']);
                    $d->addPage(PageSize::A4)->fitTable($table, new Box(0.0, 0.0, 100.0, 100.0));
                },
                LayoutException::class,
            ],
        ];
    }

    /**
     * @param Closure(): mixed               $failure
     * @param class-string<PdfException>    $type
     */
    #[Test]
    #[DataProvider('failureProvider')]
    public function throwsTheSubtypeForTheKindOfFailure(Closure $failure, string $type): void
    {
        $this->expectException($type);
        $failure();
    }
}
