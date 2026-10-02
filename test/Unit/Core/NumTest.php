<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core;

use Php2Pdf\Core\Num;
use Php2Pdf\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use const INF;
use const NAN;

#[Group('unit')]
final class NumTest extends TestCase
{
    /** @return array<string, array{0: float, 1: string}> */
    public static function nonFiniteProvider(): array
    {
        return [
            'not a number'      => [NAN, 'NAN'],
            'infinity'          => [INF, 'INF'],
            'negative infinity' => [-INF, '-INF'],
        ];
    }

    /** @return array<string, array{0: float|int, 1: string}> */
    public static function numberProvider(): array
    {
        return [
            'integer'        => [42, '42'],
            'whole float'    => [3.0, '3'],
            'trailing zeros' => [1.5, '1.5'],
            'rounded'        => [0.123_456, '0.1235'],
            'negative zero'  => [-0.000_01, '0'],
        ];
    }

    #[Test]
    #[DataProvider('numberProvider')]
    public function formatsNumbersForPdfSyntax(float|int $number, string $expected): void
    {
        self::assertSame($expected, Num::format($number));
    }

    #[Test]
    #[DataProvider('nonFiniteProvider')]
    public function refusesNumbersPdfCannotExpress(float $number, string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("{$name} cannot be written into a PDF.");
        Num::format($number);
    }
}
