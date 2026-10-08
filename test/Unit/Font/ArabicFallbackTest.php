<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Font\ArabicFallback;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Arabic presentation forms for fonts without shaping lookups. */
#[Group('unit')]
final class ArabicFallbackTest extends TestCase
{
    #[Test]
    public function lamAndAlefStayApartWhenTheFontLacksTheLigature(): void
    {
        $sequence = [[10, [0], 0, 0, 'init'], [11, [1], 0, 0, 'fina']];

        self::assertSame($sequence, ArabicFallback::lamAlef($sequence, [0x0644, 0x0627], static fn(int $_): int => 0));
    }
}
