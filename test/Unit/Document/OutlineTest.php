<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Document;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class OutlineTest extends TestCase
{
    #[Test]
    public function rootsListsTopLevelBookmarksInOrder(): void
    {
        $outline = (new Document(new MemoryOutput()))->outline();
        $first   = $outline->add('One', 1);
        $first->add('Child', 1);
        $second = $outline->add('Two', 1);

        self::assertSame([$first, $second], $outline->roots());
    }
}
