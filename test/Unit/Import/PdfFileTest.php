<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Import\PdfFile;
use Php2PdfTest\TestAsset\HostileFiles;
use Php2PdfTest\TestAsset\Import\CrossReferencePdf;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class PdfFileTest extends TestCase
{
    #[Test]
    public function readsTheVersionFromTheHeader(): void
    {
        self::assertSame('1.7', PdfFile::fromData(HostileFiles::pages(1))->version());
    }

    #[Test]
    public function readsUtf8DocumentInformationAfterAByteOrderMark(): void
    {
        $file = PdfFile::fromData(CrossReferencePdf::titled("\xEF\xBB\xBFCaf\xC3\xA9"));

        self::assertSame("Caf\u{E9}", $file->info()['Title'] ?? null);
    }
}
