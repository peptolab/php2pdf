<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Canvas;

use Php2Pdf\Canvas\ContentStream;
use Php2Pdf\Canvas\TextLine;
use Php2Pdf\Geometry\Matrix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * The operators that start each run's line in a text object: the first absolute, the rest moved
 * from the line before.
 */
#[Group('unit')]
final class TextLineTest extends TestCase
{
    /** @return array<string, array{0: list<Matrix>, 1: list<string>}> the runs' text matrices, and their operators */
    public static function movesProvider(): array
    {
        return [
            'the first line from the text object\'s start'                     => [
                [Matrix::translation(10.0, 20.0)],
                ['10 20 Td'],
            ],
            'a line across, relative'                                          => [
                [Matrix::translation(10.0, 20.0), Matrix::translation(30.5, 20.0)],
                ['10 20 Td',                      '20.5 0 Td'],
            ],
            'lines down: the leading set once, then T*'                        => [
                [Matrix::translation(10.0, 100.0), Matrix::translation(10.0, 87.0), Matrix::translation(10.0, 74.0)],
                ['10 100 Td',                      '13 TL T*',                      'T*'],
            ],
            'a top-down line set with Tm, moved down in text space'            => [
                [new Matrix(1.0, 0.0, 0.0, -1.0, 10.0, 20.0), new Matrix(1.0, 0.0, 0.0, -1.0, 10.0, 33.0)],
                ['1 0 0 -1 10 20 Tm', '13 TL T*'],
            ],
            'a matrix of another scale, set with Tm'                           => [
                [Matrix::translation(10.0, 20.0), new Matrix(2.0, 0.0, 0.0, 2.0, 10.0, 20.0)],
                ['10 20 Td', '2 0 0 2 10 20 Tm'],
            ],
            'a level line after a turned one, set with Tm'                     => [
                [new Matrix(0.0, 1.0, -1.0, 0.0, 10.0, 20.0), Matrix::translation(5.0, 5.0)],
                ['0 1 -1 0 10 20 Tm', '1 0 0 1 5 5 Tm'],
            ],
            'a line at the same place'                                         => [
                [Matrix::translation(10.0, 20.0), Matrix::translation(10.0, 20.0)],
                ['10 20 Td',                      '0 0 Td'],
            ],
            'a move measured from the position written, not the one asked for' => [
                [Matrix::translation(10.0, 100.000_000_4), Matrix::translation(10.0, 87.000_000_8)],
                ['10 100 Td',                              '12.999999 TL T*'],
            ],
        ];
    }

    /**
     * @param list<Matrix> $matrices
     * @param list<string> $operators
     */
    #[Test]
    #[DataProvider('movesProvider')]
    public function eachLineIsMovedFromTheOneBefore(array $matrices, array $operators): void
    {
        $line = new TextLine(new ContentStream(100.0, topDown: false, inheritsState: false));

        self::assertSame($operators, array_map($line->to(...), $matrices));
    }

    #[Test]
    public function theLeadingCarriesFromOneTextObjectToTheNext(): void
    {
        $line = new TextLine(new ContentStream(100.0, topDown: false, inheritsState: false));
        $line->to(Matrix::translation(10.0, 100.0));
        $line->to(Matrix::translation(10.0, 87.0));
        $line->begin();

        self::assertSame(
            ['10 50 Td', 'T*'],
            [$line->to(Matrix::translation(10.0, 50.0)), $line->to(Matrix::translation(10.0, 37.0))],
        );
    }
}
