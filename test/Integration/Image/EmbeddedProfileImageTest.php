<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Image;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Image\ImageOptions;
use Php2PdfTest\TestAsset\Image\PngChunks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function gzcompress;
use function str_pad;
use function str_repeat;

/** An image's own ICC profile is written once as an ICCBased colour space for its samples. */
#[Group('integration')]
final class EmbeddedProfileImageTest extends TestCase
{
    private static function document(MemoryOutput $out): Document
    {
        return new Document($out, new DocumentOptions(
            clock: new FixedClock('2026-01-01'),
            compress: false,
            xmp: false,
        ));
    }

    /** A one-pixel grey PNG carrying a grey ICC profile in its iCCP chunk. */
    private static function png(): string
    {
        $profile = str_pad(str_repeat("\0", times: 16) . 'GRAY', length: 128, pad_string: "\0");

        return PngChunks::file(
            PngChunks::header(
                width: 1,
                height: 1,
            ),
            PngChunks::chunk('iCCP', "Grey\0\0" . (string) gzcompress($profile)),
            PngChunks::imageData("\0\x80"),
        );
    }

    #[Test]
    public function keepsTheResolutionAskedForVertically(): void
    {
        $image = self::document(new MemoryOutput())->imageFromData(self::png(), new ImageOptions(dpi: 144.0));

        self::assertSame(144.0, $image->dpiY());
    }

    #[Test]
    public function writesTheProfileAsTheImageColourSpace(): void
    {
        $out = new MemoryOutput();
        $doc = self::document($out);
        $doc->addPage()->place($doc->imageFromData(self::png()), new Box(10.0, 10.0, 20.0, 20.0));
        $doc->close();

        self::assertMatchesRegularExpression(
            '#/Subtype/Image/Width 1/Height 1/ColorSpace\[/ICCBased \d+ 0 R\]#',
            $out->contents(),
        );
    }
}
