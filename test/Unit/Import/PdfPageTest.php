<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Import\PageBox;
use Php2Pdf\Import\PdfFile;
use Php2PdfTest\TestAsset\HostileFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class PdfPageTest extends TestCase
{
    /** @return array<string, array{0: string, 1: PageBox, 2: list<float>}> */
    public static function boxProvider(): array
    {
        return [
            'an art box'                        => [
                '/MediaBox [0 0 100 100] /ArtBox [10 10 50 50]',
                PageBox::Art,
                [10.0, 10.0, 50.0, 50.0],
            ],
            'a media box with a non-number'     => ['/MediaBox [0 0 /W 100]', PageBox::Media, [0.0, 0.0, 612.0, 792.0]],
            'no art box falls back to the crop' => [
                '/MediaBox [0 0 100 100] /CropBox [5 5 95 95]',
                PageBox::Art,
                [5.0, 5.0, 95.0, 95.0],
            ],
        ];
    }

    /** @param list<float> $expected */
    #[Test]
    #[DataProvider('boxProvider')]
    public function readsThePageBoxes(string $entries, PageBox $box, array $expected): void
    {
        $file = PdfFile::fromData(HostileFiles::pdf([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R {$entries} >>",
        ]));

        self::assertSame($expected, $file->page(1)->box($box)->toRect());
    }
}
