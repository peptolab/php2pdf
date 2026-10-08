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
final class ImportedDocumentTest extends TestCase
{
    #[Test]
    public function countsTheSourcePages(): void
    {
        $doc = new Document(new MemoryOutput());

        self::assertSame(3, $doc->import(PdfFile::fromData(HostileFiles::pages(3)))->pageCount());
    }
}
