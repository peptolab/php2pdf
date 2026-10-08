<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Svg;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Svg\RsvgConverter;
use Php2Pdf\Svg\SvgFile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chmod;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function realpath;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/** The rsvg-convert converter when the command cannot be run or fails. */
#[Group('integration')]
final class RsvgConverterTest extends TestCase
{
    private ?string $directory = null;

    #[Test]
    public function aCommandThatFailsReportsItsExitStatusAndErrorOutput(): void
    {
        $this->directory = (string) tempnam((string) realpath(sys_get_temp_dir()), prefix: 'php2pdf-rsvg-');
        unlink($this->directory);
        mkdir($this->directory);
        $binary = "{$this->directory}/rsvg-convert";
        file_put_contents($binary, data: "#!/bin/sh\necho 'cannot convert' >&2\nexit 3\n");
        chmod($binary, permissions: 0o700);

        $this->expectException(IoException::class);
        $this->expectExceptionMessage("{$binary} failed (3): cannot convert");
        (new RsvgConverter($binary))->convert(
            SvgFile::fromData('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>'),
            new Document(new MemoryOutput()),
        );
    }

    #[Test]
    public function aMissingCommandIsNotAvailable(): void
    {
        self::assertFalse((new RsvgConverter('/nonexistent/php2pdf/rsvg-convert'))->isAvailable());
    }

    #[Override]
    protected function tearDown(): void
    {
        if (null === $this->directory || ! is_dir($this->directory)) {
            return;
        }
        if (is_file("{$this->directory}/rsvg-convert")) {
            unlink("{$this->directory}/rsvg-convert");
        }
        rmdir($this->directory);
    }
}
