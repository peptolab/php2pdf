<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Table;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Table\Padding;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class PaddingTest extends TestCase
{
    #[Test]
    public function paddingInMillimetresIsGivenInPoints(): void
    {
        $padding = Padding::mm(1.0, 2.0, 3.0, 4.0);

        self::assertEqualsWithDelta(
            [2.835, 5.669, 8.504, 11.339],
            [$padding->top, $padding->right, $padding->bottom, $padding->left],
            0.001,
        );
    }

    #[Test]
    public function rejectsNegativePadding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Padding cannot be negative.');
        Padding::symmetric(2.0, -1.0);
    }
}
