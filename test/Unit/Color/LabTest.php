<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Color;

use Php2Pdf\Color\DeviceN;
use Php2Pdf\Color\Lab;
use Php2Pdf\Color\Spot;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function preg_match_all;

/** CIE Lab colours and Lab spot alternates (#37). */
#[Group('unit')]
final class LabTest extends TestCase
{
    /** @return array<string, array{0: float, 1: float, 2: float}> */
    public static function outOfRangeProvider(): array
    {
        return [
            'lightness above 100' => [100.5, 0.0, 0.0],
            'negative lightness'  => [-1.0, 0.0, 0.0],
            'a below -128'        => [50.0, -129.0, 0.0],
            'b above 127'         => [50.0, 0.0, 128.0],
        ];
    }

    #[Test]
    public function aLabColourIsDrawnInTheD50LabSpace(): void
    {
        $pdf = $this->draw(new Lab(60.0, -35.0, -5.0), new Lab(30.0, 10.0, 20.0));

        self::assertSame(
            [1, 1, 1],
            [
                preg_match_all('#\[/Lab <</WhitePoint\[0\.9642 1 0\.8249\]/Range\[-128 127 -128 127\]>>\]#', $pdf),
                preg_match("#/CS1 cs 60 -35 -5 scn\n#", $pdf),
                preg_match("#/CS1 cs 30 10 20 scn\n#", $pdf),
            ],
        );
    }

    #[Test]
    public function aSpotWithALabAlternateTintsFromLabWhite(): void
    {
        $pdf = $this->draw(new Spot('Harbour Blue', new Lab(60.0, -35.0, -5.0), 0.4));

        self::assertSame(
            1,
            preg_match(
                '#\[/Separation /Harbour\#20Blue \d+ 0 R <</FunctionType 2/Domain\[0 1\]/C0\[100 0 0\]/C1\[60 -35 -5\]/N 1>>\]#',
                $pdf,
            ),
        );
    }

    #[Test]
    public function deviceNRefusesLabColorants(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Colorant "Harbour Blue" has a Lab alternate');
        new DeviceN([DeviceN::black(), new Spot('Harbour Blue', new Lab(60.0, -35.0, -5.0))]);
    }

    #[Test]
    #[DataProvider('outOfRangeProvider')]
    public function valuesOutsideTheLabRangeAreRefused(float $l, float $a, float $b): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('L* from 0 to 100 and a*, b* from -128 to 127');
        new Lab($l, $a, $b);
    }

    private function draw(Lab|Spot ...$colours): string
    {
        $out    = new MemoryOutput();
        $doc    = new Document($out, new DocumentOptions(compress: false));
        $canvas = $doc->addPage(PageSize::A4)->canvas();
        foreach ($colours as $colour) {
            $canvas->setFillColor($colour)->rect(0.0, 0.0, 10.0, 10.0)->fill();
        }
        $doc->close();

        return $out->contents();
    }
}
