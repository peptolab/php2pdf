<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Navigation;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Navigation\Destination;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class DestinationTest extends TestCase
{
    #[Test]
    public function aPageDestinationNeedsThePageReference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The destination page does not exist.');
        Destination::page(1)->toPdf(null);
    }

    #[Test]
    public function rejectsAnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A destination name cannot be empty.');
        Destination::named('');
    }

    #[Test]
    public function rejectsPageZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Page numbers start at 1.');
        Destination::page(0);
    }
}
