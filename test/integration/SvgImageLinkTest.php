<?php

declare(strict_types=1);

namespace PhpPdfTest\Integration;

use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Document;
use PhpPdf\Document\PageSize;
use PhpPdf\Geometry\Box;
use PhpPdf\Svg\NativeConverter;
use PhpPdf\Svg\SvgFile;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function is_dir;
use function is_link;
use function mkdir;
use function rmdir;
use function symlink;
use function unlink;

/** A symbolic link cannot lead an SVG image reference out of the SVG's directory (#43). */
#[Group('integration')]
final class SvgImageLinkTest extends TestCase
{
    private string $directory;

    #[Test]
    public function refusesALinkOutOfTheDirectory(): void
    {
        symlink(PdfTools::assetPath('images/rgb8.png'), "{$this->directory}/linked.png");
        $converter = new NativeConverter();
        $doc       = new Document(new MemoryOutput());
        $svg       = SvgFile::fromData(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><image width="10" height="10" href="linked.png"/></svg>',
            $this->directory,
        );
        $doc->addPage(PageSize::A4)->place($doc->svg($svg, $converter), new Box(0.0, 0.0, 100.0, 100.0));
        $doc->close();

        self::assertSame(['image files outside the SVG\'s directory'], $converter->warnings());
    }

    protected function setUp(): void
    {
        $this->directory = PdfTools::outputDir() . '/svg-link';
        $this->tearDown();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        if (is_link("{$this->directory}/linked.png")) {
            unlink("{$this->directory}/linked.png");
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }
}
