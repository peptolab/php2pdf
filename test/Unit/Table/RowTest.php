<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Table;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Table\Row;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class RowTest extends TestCase
{
    #[Test]
    public function rejectsANegativeMinimumHeight(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A row height cannot be negative.');
        new Row(['a'], minHeight: -1.0);
    }
}
