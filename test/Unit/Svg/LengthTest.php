<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Svg\Length;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class LengthTest extends TestCase
{
    #[Test]
    public function anExIsHalfTheFontSize(): void
    {
        self::assertSame(12.0, Length::parse('2ex', fontSize: 12.0));
    }

    #[Test]
    public function textWithoutNumbersHasNoNumbers(): void
    {
        self::assertSame([], Length::numbers('none'));
    }
}
