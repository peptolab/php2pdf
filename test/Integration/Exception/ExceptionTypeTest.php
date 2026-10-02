<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Exception;

use Closure;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Document\PdfX;
use Php2Pdf\Exception\ConformanceException;
use Php2Pdf\Exception\LayoutException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Image\ImageReader;
use Php2Pdf\Import\PdfFile;
use Php2Pdf\Svg\SvgFile;
use Php2Pdf\Table\Column;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\TextFormat;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Failures throw a PdfException subtype that says what kind of problem it is (#28). */
#[Group('integration')]
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
                    $table = $d->table([Column::pt(300.0)]);
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
