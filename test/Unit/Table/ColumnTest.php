<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Table;

use Closure;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Table\Column;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ColumnTest extends TestCase
{
    /** @return array<string, array{0: Closure(): Column}> */
    public static function nonPositiveWidthProvider(): array
    {
        return [
            'zero points'      => [static fn(): Column => Column::pt(0.0)],
            'negative percent' => [static fn(): Column => Column::percent(-10.0)],
        ];
    }

    #[Test]
    public function aWidthInMillimetresIsGivenInPoints(): void
    {
        self::assertEqualsWithDelta(28.346, Column::mm(10.0)->points, 0.001);
    }

    #[Test]
    public function rejectsBothAFixedWidthAndAPercentage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A column is fixed or a percentage, not both.');
        new Column(100.0, 50.0);
    }

    /** @param Closure(): Column $make */
    #[Test]
    #[DataProvider('nonPositiveWidthProvider')]
    public function rejectsWidthsThatAreNotPositive(Closure $make): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Column widths must be positive.');
        $make();
    }
}
