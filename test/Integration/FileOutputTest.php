<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Core\Output\FileOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Document\PdfX;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\PdfException;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function str_starts_with;
use function unlink;

/** A file only appears, or is replaced, when its document closes successfully. */
#[Group('integration')]
final class FileOutputTest extends TestCase
{
    private string $directory;

    #[Test]
    public function aClosedDocumentIsWrittenWithNoTemporaryFileLeft(): void
    {
        $path = "{$this->directory}/written.pdf";
        $doc  = new Document($path);
        $doc->addPage(PageSize::A4);
        $doc->close();

        self::assertSame(
            [true, ["{$this->directory}/written.pdf"]],
            [str_starts_with((string) file_get_contents($path), '%PDF-'), glob("{$this->directory}/*")],
        );
    }

    #[Test]
    public function aDocumentThatFailsToCloseKeepsThePreviousFile(): void
    {
        $path = "{$this->directory}/kept.pdf";
        file_put_contents($path, data: 'previous');
        $this->failToClose($path);

        self::assertSame(['previous', [$path]], [file_get_contents($path), glob("{$this->directory}/*")]);
    }

    #[Test]
    public function aDocumentThatFailsToCloseLeavesNoFile(): void
    {
        $this->failToClose("{$this->directory}/failed.pdf");

        self::assertSame([], glob("{$this->directory}/*"));
    }

    #[Test]
    public function anUnwritableDirectoryIsReported(): void
    {
        $this->expectException(IoException::class);
        new FileOutput("{$this->directory}/missing/out.pdf");
    }

    protected function setUp(): void
    {
        $this->directory = PdfTools::outputDir() . '/file-output';
        $this->tearDown();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->directory)) {
            return;
        }
        foreach (glob("{$this->directory}/*") ?? [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /** A PDF/X document without an output intent fails when it closes. */
    private function failToClose(string $path): void
    {
        $doc = new Document($path, new DocumentOptions(pdfx: PdfX::X4));
        $doc->addPage(PageSize::A4);
        try {
            $doc->close();
            self::fail('The document was expected to fail.');
        } catch (PdfException) {
            unset($doc);
        }
        self::assertTrue(! is_file($path) || 'previous' === file_get_contents($path));
    }
}
