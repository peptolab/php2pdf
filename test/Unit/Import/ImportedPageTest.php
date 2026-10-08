<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Import\PdfFile;
use Php2PdfTest\TestAsset\HostileFiles;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ImportedPageTest extends TestCase
{
    #[Test]
    public function isBoundedByTheSourcePagesCropBox(): void
    {
        $page = (new Document(new MemoryOutput()))->import(PdfFile::fromData(HostileFiles::pages(1)))
            ->page(1);

        self::assertSame([0.0, 0.0, 100.0, 100.0], $page->boundingBox()->toRect());
    }
}
