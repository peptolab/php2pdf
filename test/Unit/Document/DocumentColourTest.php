<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Document;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PdfX;
use Php2PdfTest\TestAsset\StubIccProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class DocumentColourTest extends TestCase
{
    #[Test]
    public function aGreyOutputIntentBlendsPagesInDeviceGray(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
            pdfx: PdfX::X4,
        ));
        $doc->setOutputIntent(StubIccProfile::of('GRAY'), 'Test condition');
        $doc->info()->setTitle('PDF/X');
        $doc->addPage();
        $doc->close();

        self::assertStringContainsString('/Group<</S/Transparency/CS/DeviceGray', $out->contents());
    }
}
