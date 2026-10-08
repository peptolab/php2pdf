<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Table;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Table\Border;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class BorderTest extends TestCase
{
    #[Test]
    public function noneIsInvisible(): void
    {
        self::assertFalse(Border::none()->isVisible());
    }

    #[Test]
    public function rejectsANegativeWidth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Border width cannot be negative.');
        new Border(-0.5);
    }
}
