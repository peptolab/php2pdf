<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType;

use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Font\OpenType\KernTable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pack;

#[Group('unit')]
final class KernTableTest extends TestCase
{
    #[Test]
    public function anAppleVersionOneTableGivesNoPairs(): void
    {
        $kern = pack('NN', 0x0001_0000, 1) . pack('NnnnnnnnnN', 26, 0, 0, 1, 6, 0, 0, 3, 4, 0xFF9C);

        self::assertSame([], KernTable::parse(new BinaryReader($kern)));
    }

    #[Test]
    public function aVersionZeroTableGivesItsHorizontalPairs(): void
    {
        $kern = pack('nn', 0, 1) . pack('nnnnnnn', 0, 20, 0x0001, 1, 6, 0, 0) . pack('nnn', 3, 4, 0xFF9C);

        self::assertSame([(3 << 16) | 4 => -100], KernTable::parse(new BinaryReader($kern)));
    }
}
