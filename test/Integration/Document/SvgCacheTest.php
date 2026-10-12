<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Document;

use ArrayObject;
use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Svg\SvgFile;
use Php2PdfTest\TestAsset\CountingSvgConverter;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function file_get_contents;

/** An SVG is converted once for each converter, and a new converter never gets another's result. */
#[Group('integration')]
final class SvgCacheTest extends TestCase
{
    private Document $doc;
    private string $path;

    /** @var ArrayObject<int, string> */
    private ArrayObject $tally;

    #[Test]
    public function aFileIsConvertedOnceForOneConverter(): void
    {
        $converter = new CountingSvgConverter($this->tally);
        $first     = $this->doc->svg($this->path, $converter);

        self::assertSame(
            [$first, $first, 1],
            [
                $this->doc->svg($this->path, $converter),
                $this->doc->svg(SvgFile::open($this->path), $converter),
                count($this->tally),
            ],
        );
    }

    #[Test]
    public function aFileOpenedFromItsPathFirstIsTheArtworkThePathGives(): void
    {
        $converter = new CountingSvgConverter($this->tally);
        $first     = $this->doc->svg(SvgFile::open($this->path), $converter);

        self::assertSame([$first, 1], [$this->doc->svg($this->path, $converter), count($this->tally)]);
    }

    #[Test]
    public function aFileWithoutAPathIsConvertedOnceWhileItLives(): void
    {
        $converter = new CountingSvgConverter($this->tally);
        $file      = SvgFile::fromData((string) file_get_contents($this->path));
        $this->doc->svg($file, $converter);
        $this->doc->svg($file, $converter);
        $this->doc->svg(SvgFile::fromData((string) file_get_contents($this->path)), $converter);

        self::assertCount(2, $this->tally);
    }

    #[Test]
    public function aNewConverterConvertsAgainEvenWhenAnOldOnesIdIsReused(): void
    {
        $this->doc->svg($this->path, new CountingSvgConverter($this->tally));
        $this->doc->svg($this->path, new CountingSvgConverter($this->tally));

        self::assertCount(2, $this->tally);
    }

    #[Test]
    public function withoutAConverterTheNativeOneIsSharedSoAFileIsConvertedOnce(): void
    {
        self::assertSame($this->doc->svg($this->path), $this->doc->svg($this->path));
    }

    #[Override]
    protected function setUp(): void
    {
        $this->doc   = new Document(new MemoryOutput());
        $this->path  = PdfTools::assetPath('svg/shapes.svg');
        $this->tally = new ArrayObject();
    }
}
