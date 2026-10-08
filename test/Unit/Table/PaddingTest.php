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
    public function rejectsNegativePadding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Padding cannot be negative.');
        Padding::symmetric(2.0, -1.0);
    }
}
