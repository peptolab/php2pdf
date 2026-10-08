<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Core\Output;

use Override;
use Php2Pdf\Core\Output\FileOutput;
use Php2Pdf\Exception\IoException;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function unlink;

#[Group('integration')]
final class FileOutputTest extends TestCase
{
    private string $directory;

    #[Test]
    public function closingTwiceKeepsTheFinishedFile(): void
    {
        $path   = "{$this->directory}/twice.pdf";
        $output = new FileOutput($path);
        $output->write('%PDF-1.7');
        $output->close();
        $output->close();

        self::assertSame('%PDF-1.7', file_get_contents($path));
    }

    #[Test]
    public function leavesNoTemporaryFileWhenTheMoveFails(): void
    {
        $path = "{$this->directory}/occupied";
        mkdir($path);
        $output = new FileOutput($path);
        $output->write('%PDF-1.7');
        try {
            $output->close();
            self::fail('The move was expected to fail.');
        } catch (IoException) {
            self::assertSame([$path], glob("{$this->directory}/*"));
        }
    }

    #[Test]
    public function refusesWritesAfterClosing(): void
    {
        $output = new FileOutput("{$this->directory}/closed.pdf");
        $output->close();

        $this->expectException(IoException::class);
        $this->expectExceptionMessage('Output is closed.');

        $output->write('%PDF-1.7');
    }

    #[Test]
    public function reportsAFinishedFileThatCannotBeMovedIntoPlace(): void
    {
        $path = "{$this->directory}/occupied";
        mkdir($path);
        $output = new FileOutput($path);

        $this->expectException(IoException::class);
        $this->expectExceptionMessage("Cannot move the finished PDF to \"{$path}\".");

        $output->close();
    }

    #[Override]
    protected function setUp(): void
    {
        $this->directory = PdfTools::outputDir() . '/core-file-output';
        $this->tearDown();
        mkdir($this->directory);
    }

    #[Override]
    protected function tearDown(): void
    {
        if (! is_dir($this->directory)) {
            return;
        }
        $entries = glob("{$this->directory}/*");
        self::assertNotFalse($entries);
        foreach ($entries as $entry) {
            if (is_dir($entry)) {
                rmdir($entry);
                continue;
            }
            unlink($entry);
        }
        rmdir($this->directory);
    }
}
