<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\Bidi;

use Php2Pdf\Font\Bidi\BidiClass;
use Php2Pdf\Font\Bidi\BidiParagraph;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class BidiParagraphTest extends TestCase
{
    #[Test]
    public function anEmptyLineHasNoVisualOrder(): void
    {
        self::assertSame([], (new BidiParagraph([BidiClass::R, BidiClass::L]))->visualOrder(1, 1));
    }
}
