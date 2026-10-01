<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Color;

use Php2Pdf\Color\Cmyk;
use Php2Pdf\Color\Gray;
use Php2Pdf\Color\Lab;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Color\Spot;
use Php2Pdf\Color\SpotLibrary;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2PdfTest\Support\AseBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function substr;

/** Spot colours from swatch files of your own (#37). The colour names are fictional. */
#[Group('unit')]
final class SpotLibraryTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function badAseProvider(): array
    {
        $valid = (new AseBuilder())->colour('Harbour Blue', 'CMYK', [1.0, 0.5, 0.0, 0.0])
            ->toString();

        return [
            'not a swatch file'  => ['GIF89a and more bytes', 'Not an Adobe Swatch Exchange file.'],
            'a block cut short'  => [substr($valid, offset: 0, length: -4), 'ends inside a block'],
            'an unknown model'   => [
                (new AseBuilder())->colour('Odd', 'HSB ', [0.1, 0.2, 0.3])
                    ->toString(),
                'unknown colour model "HSB "',
            ],
            'a swatch cut short' => [
                (new AseBuilder())->colour('Short', 'CMYK', [0.1, 0.2])
                    ->toString(),
                'cut short',
            ],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badCsvProvider(): array
    {
        return [
            'no name column'       => ["c,m,y,k\n1,2,3,4", 'needs a "name" column'],
            'no colour columns'    => ["name,hue\nHarbour Blue,200", 'needs c, m, y, k or l, a, b or r, g, b columns'],
            'a word for a number'  => [
                "name,l,a,b\nHarbour Blue,fifty,0,0",
                'Row 2 of the swatch table has "fifty" where a number belongs.',
            ],
            'a row without a name' => ["name,l,a,b\n,50,0,0", 'Row 2 of the swatch table has no name.'],
        ];
    }

    #[Test]
    public function aMissingFileIsReported(): void
    {
        $this->expectException(IoException::class);
        $this->expectExceptionMessage('Cannot read swatch file');
        SpotLibrary::openAse('/nonexistent/inks.ase');
    }

    #[Test]
    public function anUnknownNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The swatch library has no colour named "Nowhere Blue".');
        (new SpotLibrary())->get('Nowhere Blue');
    }

    #[Test]
    public function aseProcessAndGlobalSwatchesAreSkipped(): void
    {
        $library = SpotLibrary::fromAseData(
            (new AseBuilder())->colour('Process Teal', 'CMYK', [0.8, 0.0, 0.3, 0.0], kind: 2)
                ->colour('Global Teal', 'CMYK', [0.8, 0.0, 0.3, 0.0], kind: 0)
                ->colour('Spot Teal', 'CMYK', [0.8, 0.0, 0.3, 0.0])
                ->toString(),
        );

        self::assertSame(['Spot Teal'], $library->names());
    }

    #[Test]
    public function aseSpotSwatchesKeepTheirModels(): void
    {
        $library = SpotLibrary::fromAseData(
            (new AseBuilder())->groupStart('House inks')
                ->colour('Harbour Blue', 'LAB ', [0.6, -35.0, -5.0])
                ->colour('Brick Red', 'CMYK', [0.0, 0.8, 0.7, 0.1])
                ->colour('Signal Green', 'RGB ', [0.0, 0.5, 0.25])
                ->colour('Slate', 'Gray', [0.4])
                ->groupEnd()
                ->toString(),
        );

        self::assertEqualsWithDelta(
            [
                new Spot('Harbour Blue', new Lab(60.0, -35.0, -5.0)),
                new Spot('Brick Red', new Cmyk(0.0, 0.8, 0.7, 0.1)),
                new Spot('Signal Green', new Rgb(0.0, 0.5, 0.25)),
                new Spot('Slate', new Gray(0.4)),
            ],
            [
                $library->get('Harbour Blue'),
                $library->get('Brick Red'),
                $library->get('Signal Green'),
                $library->get('Slate'),
            ],
            1.0e-5,
        );
    }

    #[Test]
    public function csvTablesGiveCmykLabOrRgb(): void
    {
        $cmyk = SpotLibrary::fromCsvData("Name,C,M,Y,K\n\"Brick Red, matte\",0,80,70,10\n\n");
        $lab  = SpotLibrary::fromCsvData("name,l,a,b\nHarbour Blue,60,-35,-5");
        $rgb  = SpotLibrary::fromCsvData("name,r,g,b\nSignal Green,0,255,51");

        self::assertEquals(
            [new Cmyk(0.0, 0.8, 0.7, 0.1), new Lab(60.0, -35.0, -5.0), new Rgb(0.0, 1.0, 0.2)],
            [
                $cmyk->get('Brick Red, matte')->alternate,
                $lab->get('Harbour Blue')->alternate,
                $rgb->get('Signal Green')->alternate,
            ],
        );
    }

    #[Test]
    public function hasTellsWhetherANameIsDefined(): void
    {
        $library = new SpotLibrary(['Harbour Blue' => new Lab(60.0, -35.0, -5.0)]);

        self::assertSame([true, false], [$library->has('harbour blue'), $library->has('Harbour Green')]);
    }

    #[Test]
    #[DataProvider('badAseProvider')]
    public function malformedAseDataIsRefused(string $data, string $message): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($message);
        SpotLibrary::fromAseData($data);
    }

    #[Test]
    #[DataProvider('badCsvProvider')]
    public function malformedCsvIsRefused(string $data, string $message): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($message);
        SpotLibrary::fromCsvData($data);
    }

    #[Test]
    public function namesMatchIgnoringCaseAndSpacing(): void
    {
        $library = new SpotLibrary(['HOUSE  300 C' => new Lab(40.0, -5.0, -50.0)]);

        self::assertSame(['HOUSE  300 C', 0.5], [
            $library->get(' house 300 c ', 0.5)->name,
            $library->get('House 300 C', 0.5)->tint,
        ]);
    }

    #[Test]
    public function numericNamesStayNames(): void
    {
        $library = (new SpotLibrary())->with('300', new Gray(0.2))
            ->with('185', new Gray(0.6));

        self::assertSame([['185', '300'], 2, '300'], [
            $library->names(),
            $library->count(),
            $library->get('300')->name,
        ]);
    }
}
