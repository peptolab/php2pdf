<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Document;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Image\ImageOptions;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function copy;
use function is_file;
use function realpath;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/** Images and imported PDFs are read and written once however often they are asked for. */
#[Group('integration')]
final class AssetCacheTest extends TestCase
{
    private Document $doc;
    private string $file;

    #[Test]
    public function anImageAtAnotherResolutionSharesItsPixels(): void
    {
        $path   = PdfTools::assetPath('images/gray8.png');
        $plain  = $this->doc->image($path);
        $scaled = $this->doc->image($path, new ImageOptions(dpi: 144.0));

        self::assertSame(
            [true, true],
            [
                $plain->reference() === $scaled->reference(),
                $plain->naturalSize()->width === (2.0 * $scaled->naturalSize()->width),
            ],
        );
    }

    #[Test]
    public function anImageFileChangedOnDiskIsReadAgain(): void
    {
        copy(PdfTools::assetPath('images/gray8.png'), $this->file);
        $grey = $this->doc->image($this->file);
        copy(PdfTools::assetPath('images/rgb-150dpi.png'), $this->file);

        self::assertNotSame($grey->reference(), $this->doc->image($this->file)->reference());
    }

    #[Test]
    public function aPdfImportedAgainIsNotOpenedAgain(): void
    {
        copy(PdfTools::assetPath('pdf/cairo.pdf'), $this->file);
        $first = $this->doc->import($this->file);
        unlink($this->file);

        self::assertSame($first, $this->doc->import($this->file));
    }

    #[Override]
    protected function setUp(): void
    {
        $this->doc  = new Document(new MemoryOutput());
        $this->file = (string) tempnam((string) realpath(sys_get_temp_dir()), prefix: 'php2pdf-asset-');
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }
}
