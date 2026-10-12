<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Import\PdfFile;
use Php2PdfTest\TestAsset\HostileFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sort;
use function sprintf;
use function strlen;

#[Group('unit')]
final class ColourScannerTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: list<string>}> */
    public static function pageProvider(): array
    {
        $image = '<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace %s /Length 0 >>';

        return [
            'nothing'                             => ['', '<< >>', []],
            'grey fill'                           => ['0.5 g', '<< >>', ['DeviceGray']],
            'RGB stroke'                          => ['1 0 0 RG', '<< >>', ['DeviceRGB']],
            'CMYK fill'                           => ['0 0 0 1 k', '<< >>', ['DeviceCMYK']],
            'device space selected'               => ['/DeviceRGB cs 1 0 0 sc', '<< >>', ['DeviceRGB']],
            'named space'                         => [
                '/CS0 cs 1 sc',
                '<< /ColorSpace << /CS0 [/Separation /Gold /DeviceCMYK 5 0 R] >> >>',
                ['DeviceCMYK'],
            ],
            'a calibrated space'                  => [
                '',
                '<< /ColorSpace << /CS0 [/CalRGB << /WhitePoint [0.9505 1 1.089] >>] >> >>',
                [],
            ],
            'indexed base'                        => [
                '',
                '<< /ColorSpace << /CS0 [/Indexed /DeviceRGB 1 <000000FFFFFF>] >> >>',
                ['DeviceRGB'],
            ],
            'image'                               => [
                '',
                sprintf('<< /XObject << /Im0 %s >> >>', sprintf($image, '/DeviceCMYK')),
                ['DeviceCMYK'],
            ],
            'image mask'                          => [
                '',
                '<< /XObject << /Im0 << /Subtype /Image /ImageMask true /ColorSpace /DeviceRGB >> >> >>',
                [],
            ],
            'shading'                             => [
                '',
                '<< /Shading << /Sh0 << /ShadingType 2 /ColorSpace /DeviceRGB >> >> >>',
                ['DeviceRGB'],
            ],
            'shading pattern'                     => [
                '',
                '<< /Pattern << /P0 << /PatternType 2 /Shading << /ColorSpace /DeviceCMYK >> >> >> >>',
                ['DeviceCMYK'],
            ],
            'form content'                        => ['', '<< /XObject << /Fm0 6 0 R >> >>', ['DeviceRGB']],
            'inline image'                        => [
                "BI /W 1 /H 1 /CS /RGB /BPC 8 ID \x01\x02\x03 EI",
                '<< >>',
                ['DeviceRGB'],
            ],
            'inline image data is not content'    => ['BI /W 1 /H 1 /CS /G /BPC 8 ID k EI', '<< >>', ['DeviceGray']],
            'a colour space that is not an array' => ['', '<< /ColorSpace << /CS0 42 >> >>', []],
            'a colour space without a family'     => ['', '<< /ColorSpace << /CS0 [42] >> >>', []],
            'DeviceN alternate'                   => [
                '',
                '<< /ColorSpace << /CS0 [/DeviceN [/Gold /Silver] /DeviceCMYK 5 0 R] >> >>',
                ['DeviceCMYK'],
            ],
            'pattern base'                        => [
                '',
                '<< /ColorSpace << /CS0 [/Pattern /DeviceRGB] >> >>',
                ['DeviceRGB'],
            ],
            'ICC-based space'                     => ['', '<< /ColorSpace << /CS0 [/ICCBased 5 0 R] >> >>', []],
            'an XObject that is not a dictionary' => ['', '<< /XObject << /X0 42 >> >>', []],
            'a tiling pattern without content'    => ['', '<< /Pattern << /P0 << /PatternType 1 >> >> >>', []],
        ];
    }

    #[Test]
    public function aFormThatDrawsItselfIsScannedOnce(): void
    {
        $form = '/Fm0 Do 0 0 0 1 k';
        $file = PdfFile::fromData(HostileFiles::pdf([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Contents 4 0 R /Resources << /XObject << /Fm0 4 0 R >> >> >>',
            sprintf(
                "<< /Type /XObject /Subtype /Form /BBox [0 0 1 1] /Resources << /XObject << /Fm0 4 0 R >> >> /Length %d >>\nstream\n%s\nendstream",
                strlen($form),
                $form,
            ),
        ]));

        self::assertSame(['DeviceCMYK'], $file->page(1)->deviceColourSpaces());
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('pageProvider')]
    public function findsTheDeviceColourSpacesAPageUses(string $content, string $resources, array $expected): void
    {
        $form = '0 1 0 rg';
        $file = PdfFile::fromData(HostileFiles::pdf([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Contents 4 0 R /Resources %s >>',
                $resources,
            ),
            sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content),
            '<< /FunctionType 2 /Domain [0 1] /C0 [0 0 0 0] /C1 [0 0.2 0.8 0] /N 1 >>',
            sprintf(
                "<< /Type /XObject /Subtype /Form /BBox [0 0 1 1] /Length %d >>\nstream\n%s\nendstream",
                strlen($form),
                $form,
            ),
        ]));

        $spaces = $file->page(1)->deviceColourSpaces();
        sort($spaces);

        self::assertSame($expected, $spaces);
    }

    #[Test]
    public function resourcesSharedByAPageAndItsFormAreScannedOnce(): void
    {
        $form = '0 0 0 1 k';
        $file = PdfFile::fromData(HostileFiles::pdf([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources 5 0 R >>',
            sprintf(
                "<< /Type /XObject /Subtype /Form /BBox [0 0 1 1] /Resources 5 0 R /Length %d >>\nstream\n%s\nendstream",
                strlen($form),
                $form,
            ),
            '<< /XObject << /Fm0 4 0 R >> >>',
        ]));

        self::assertSame(['DeviceCMYK'], $file->page(1)->deviceColourSpaces());
    }
}
