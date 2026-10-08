<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Table;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Table\Cell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class CellTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int}> */
    public static function invalidSpanProvider(): array
    {
        return [
            'no columns' => [0, 1],
            'no rows'    => [1, 0],
        ];
    }

    /** @return array<string, array{0: Cell, 1: bool}> */
    public static function textCellProvider(): array
    {
        return [
            'plain text' => [Cell::text('a'), true],
            'markup'     => [Cell::markup('<b>a</b>'), true],
            'empty'      => [Cell::empty(), false],
        ];
    }

    #[Test]
    public function aPlaceableCellHasNoText(): void
    {
        $template = (new Document(new MemoryOutput()))->template(new Dimensions(10.0, 10.0));

        self::assertFalse(Cell::of($template)->hasText());
    }

    #[Test]
    #[DataProvider('textCellProvider')]
    public function hasTextOnlyForTextContent(Cell $cell, bool $expected): void
    {
        self::assertSame($expected, $cell->hasText());
    }

    #[Test]
    #[DataProvider('invalidSpanProvider')]
    public function rejectsSpansBelowOne(int $columns, int $rows): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cells span at least one column and one row.');
        Cell::text('a')->spanning($columns, $rows);
    }
}
