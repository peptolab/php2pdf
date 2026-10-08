<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core\Filter;

use Php2Pdf\Core\Filter\Ascii85;
use Php2Pdf\Exception\ParseException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class Ascii85Test extends TestCase
{
    #[Test]
    public function rejectsACharacterOutsideTheAscii85Range(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid ASCII85 character 0x76.');

        Ascii85::decode('9jqov');
    }
}
