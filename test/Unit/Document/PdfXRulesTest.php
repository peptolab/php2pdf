<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Document;

use Php2Pdf\Color\IccProfile;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PdfX;
use Php2Pdf\Exception\ConformanceException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_pad;
use function str_repeat;
use function trim;

#[Group('unit')]
final class PdfXRulesTest extends TestCase
{
    private static function document(string $space, bool $xmp): Document
    {
        $doc = new Document(
            new MemoryOutput(),
            new DocumentOptions(
                compress: false,
                clock: new FixedClock('2026-01-01T00:00:00Z'),
                xmp: $xmp,
                pdfx: PdfX::X4,
            ),
        );
        $doc->setOutputIntent(self::profile($space), 'Test condition');
        $doc->info()->setTitle('PDF/X');
        $doc->addPage();

        return $doc;
    }

    private static function profile(string $space): IccProfile
    {
        return new IccProfile(
            str_pad(str_repeat("\0", times: 16) . $space, length: 128, pad_string: "\0"),
            'test-' . trim($space),
        );
    }

    #[Test]
    public function aDocumentWithoutPdfXAcceptsAnyImportedColour(): void
    {
        $this->expectNotToPerformAssertions();
        (new Document(new MemoryOutput()))->colour()
            ->assertImportedColour('DeviceRGB', page: 1);
    }

    #[Test]
    public function refusesToCloseWithoutXmp(): void
    {
        $doc = self::document('CMYK', xmp: false);

        $this->expectException(ConformanceException::class);
        $this->expectExceptionMessage('PDF/X-4 needs XMP metadata; leave DocumentOptions::$xmp enabled.');
        $doc->close();
    }
}
