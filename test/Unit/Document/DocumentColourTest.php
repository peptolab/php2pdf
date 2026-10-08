<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Document;

use Php2Pdf\Color\IccProfile;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PdfX;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_pad;
use function str_repeat;
use function trim;

#[Group('unit')]
final class DocumentColourTest extends TestCase
{
    private static function profile(string $space): IccProfile
    {
        return new IccProfile(
            str_pad(str_repeat("\0", times: 16) . $space, length: 128, pad_string: "\0"),
            'test-' . trim($space),
        );
    }

    #[Test]
    public function aGreyOutputIntentBlendsPagesInDeviceGray(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
            pdfx: PdfX::X4,
        ));
        $doc->setOutputIntent(self::profile('GRAY'), 'Test condition');
        $doc->info()->setTitle('PDF/X');
        $doc->addPage();
        $doc->close();

        self::assertStringContainsString('/Group<</S/Transparency/CS/DeviceGray', $out->contents());
    }
}
