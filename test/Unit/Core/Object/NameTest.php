<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core\Object;

use Php2Pdf\Core\Object\Name;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class NameTest extends TestCase
{
    #[Test]
    public function namesWithDifferentValuesAreNotEqual(): void
    {
        self::assertFalse(Name::of('Type')->equals(new Name('Subtype')));
    }

    #[Test]
    public function namesWithTheSameValueAreEqual(): void
    {
        self::assertTrue(Name::of('Type')->equals(new Name('Type')));
    }
}
