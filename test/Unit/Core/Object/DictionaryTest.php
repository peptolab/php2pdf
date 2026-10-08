<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core\Object;

use Php2Pdf\Core\Object\Dictionary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class DictionaryTest extends TestCase
{
    #[Test]
    public function countsOnlyTheEntriesThatHoldAValue(): void
    {
        self::assertSame(2, Dictionary::of(['A' => 1, 'B' => null, 'C' => 'c'])->count());
    }
}
