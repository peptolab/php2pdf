<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core\Filter;

use Php2Pdf\Core\Filter\Lzw;
use Php2Pdf\Exception\ParseException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class LzwTest extends TestCase
{
    #[Test]
    public function decodesDataThatEndsWithoutAnEndOfDataCode(): void
    {
        self::assertSame('A', Lzw::decode("\x20\x80"));
    }

    #[Test]
    public function rejectsACodeNotYetInTheDictionary(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid LZW code 300.');

        Lzw::decode("\x96\x00");
    }
}
