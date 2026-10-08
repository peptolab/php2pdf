<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Table;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Table\TableOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class TableOptionsTest extends TestCase
{
    #[Test]
    public function rejectsANegativeMinimumRowHeight(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The minimum row height cannot be negative.');
        new TableOptions(minRowHeight: -1.0);
    }
}
