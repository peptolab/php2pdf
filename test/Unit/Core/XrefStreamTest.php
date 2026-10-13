<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core;

use Php2Pdf\Core\XrefStream;
use Php2PdfTest\TestAsset\XrefRows;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function substr;

/** A cross-reference stream's rows, in fields as wide as the largest needs, each predicted from the one above. */
#[Group('unit')]
final class XrefStreamTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int, 2: array{0: int, 1: int, 2: int}}> largest offset, largest index, widths */
    public static function widthProvider(): array
    {
        return [
            'one byte each'            => [255, 0, [1, 1, 1]],
            'an offset of two bytes'   => [256, 0, [1, 2, 1]],
            'an offset of three bytes' => [65_536, 0, [1, 3, 1]],
            'the free entry\'s 65535'  => [100, 65_535, [1, 1, 2]],
        ];
    }

    #[Test]
    public function aRowLikeTheOneAboveIsAllZerosAfterItsPredictor(): void
    {
        [, $data] = XrefStream::encode([[2, 5, 1], [2, 5, 1]]);

        self::assertSame("\x02\x00\x00\x00", substr($data, offset: 4));
    }

    /** @param array{0: int, 1: int, 2: int} $widths */
    #[Test]
    #[DataProvider('widthProvider')]
    public function eachFieldIsAsWideAsItsLargestValueNeeds(int $offset, int $index, array $widths): void
    {
        self::assertSame($widths, XrefStream::encode([[1, $offset, $index]])[0]);
    }

    #[Test]
    public function rowsReadBackAsTheEntriesTheyWereMadeFrom(): void
    {
        $entries = [[0, 0, 65_535], [1, 15, 0], [2, 5, 0], [2, 5, 1], [1, 1_000, 0]];
        [$widths, $data] = XrefStream::encode($entries);

        self::assertSame($entries, XrefRows::decode($data, $widths));
    }
}
