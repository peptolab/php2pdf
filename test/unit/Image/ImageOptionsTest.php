<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Image;

use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Document;
use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Exception\PdfException;
use PhpPdf\Image\ImageOptions;
use PhpPdfTest\Support\JpegFixtures;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;

/** Options for loading an image: resolution, page and orientation (#25). */
#[Group('unit')]
final class ImageOptionsTest extends TestCase
{
    private Document $doc;

    /** @return array<string, array{0: float|null, 1: int, 2: string}> */
    public static function invalidProvider(): array
    {
        return [
            'zero resolution' => [0.0, 1, 'An image resolution must be positive'],
            'page zero'       => [null, 0, 'Image pages are numbered from 1'],
        ];
    }

    #[Test]
    public function aJpegHasOnePage(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('JPEG and PNG files have one page; page 2 was asked for.');
        $this->doc->image(PdfTools::assetPath('images/rgb.jpg'), new ImageOptions(page: 2));
    }

    #[Test]
    public function aResolutionOverridesTheFilesOwn(): void
    {
        $image = $this->doc->image(PdfTools::assetPath('images/rgb.jpg'), new ImageOptions(dpi: 144.0));

        self::assertSame([32.0, 24.0], [$image->naturalSize()->width, $image->naturalSize()->height]);
    }

    #[Test]
    public function loadsWithOtherOptionsAreKeptApart(): void
    {
        $path = PdfTools::assetPath('images/rgb.jpg');

        self::assertSame(
            [true, false],
            [
                $this->doc->image($path) === $this->doc->image($path, new ImageOptions()),
                $this->doc->image($path) === $this->doc->image($path, new ImageOptions(dpi: 300.0)),
            ],
        );
    }

    #[Test]
    #[DataProvider('invalidProvider')]
    public function rejectsInvalidOptions(?float $dpi, int $page, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new ImageOptions($dpi, $page);
    }

    #[Test]
    public function theOrientationCanBeIgnored(): void
    {
        $jpeg  = JpegFixtures::withExif((string) file_get_contents(PdfTools::assetPath('images/rgb.jpg')), 6);
        $image = $this->doc->imageFromData($jpeg, new ImageOptions(orientation: false));

        self::assertSame([64.0, 48.0], [$image->naturalSize()->width, $image->naturalSize()->height]);
    }

    protected function setUp(): void
    {
        $this->doc = new Document(new MemoryOutput());
    }
}
