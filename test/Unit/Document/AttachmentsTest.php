<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Document;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\AttachmentRelationship;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\FacturXProfile;
use Php2Pdf\Document\PdfA;
use Php2Pdf\Document\PdfX;
use Php2Pdf\Exception\ConformanceException;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Navigation\Destination;
use Php2PdfTest\TestAsset\StubIccProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function md5;
use function preg_match;
use function str_contains;

/** Files attached to a document, and a Factur-X invoice's XML (#34). */
#[Group('unit')]
final class AttachmentsTest extends TestCase
{
    /** @return array<string, array{0: FacturXProfile, 1: string}> */
    public static function profileProvider(): array
    {
        return [
            'MINIMUM, data'      => [FacturXProfile::Minimum, 'Data'],
            'BASIC WL, data'     => [FacturXProfile::BasicWl, 'Data'],
            'BASIC, alternative' => [FacturXProfile::Basic, 'Alternative'],
            'EN 16931'           => [FacturXProfile::En16931, 'Alternative'],
            'EXTENDED'           => [FacturXProfile::Extended, 'Alternative'],
            'XRECHNUNG'          => [FacturXProfile::XRechnung, 'Alternative'],
        ];
    }

    /** @return array<string, array{0: DocumentOptions, 1: string}> */
    public static function refusedProvider(): array
    {
        return [
            'PDF/A-2b' => [new DocumentOptions(pdfa: PdfA::A2b), 'PDF/A-2b allows only PDF/A files'],
            'PDF/X-4'  => [new DocumentOptions(pdfx: PdfX::X4), 'PDF/X-4 does not allow attachments.'],
        ];
    }

    /** A document closed with the files the closure attaches; its output. */
    private static function attached(callable $attach, ?PdfA $pdfa = null): string
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T12:00:00Z'),
            pdfa: $pdfa,
        ));
        if (null !== $pdfa) {
            $doc->setOutputIntent(StubIccProfile::of('RGB '), 'Test condition');
        }
        $doc->addPage();
        $attach($doc);
        $doc->close();

        return $out->contents();
    }

    #[Test]
    #[DataProvider('profileProvider')]
    public function aFacturXInvoiceIsAttachedAndDeclaredForItsProfile(
        FacturXProfile $profile,
        string $relationship,
    ): void {
        $pdf = self::attached(
            static fn(Document $doc): Document => $doc->attachFacturX('<invoice/>', $profile),
            PdfA::A3b,
        );

        self::assertSame(
            [1, 1, 1, 1],
            [
                preg_match(
                    "#/F\\(factur-x\\.xml\\)/UF\\(factur-x\\.xml\\)/Desc\\(Factur-X invoice\\)/AFRelationship/{$relationship}/#",
                    $pdf,
                ),
                preg_match('#/Subtype/text\#2Fxml/#', $pdf),
                preg_match(
                    "#<fx:DocumentType>INVOICE</fx:DocumentType><fx:DocumentFileName>factur-x\\.xml</fx:DocumentFileName>"
                        . "<fx:Version>1\\.0</fx:Version><fx:ConformanceLevel>{$profile->value}</fx:ConformanceLevel>#",
                    $pdf,
                ),
                preg_match(
                    '#<pdfaSchema:namespaceURI>urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0\#</pdfaSchema:namespaceURI>#',
                    $pdf,
                ),
            ],
        );
    }

    #[Test]
    public function aFacturXInvoiceNeedsPdfA3(): void
    {
        $doc = new Document(new MemoryOutput(), new DocumentOptions(pdfa: PdfA::A2b));

        $this->expectException(ConformanceException::class);
        $this->expectExceptionMessage('A Factur-X invoice must be PDF/A-3b');
        $doc->attachFacturX('<invoice/>', FacturXProfile::En16931);
    }

    #[Test]
    public function aFileIsEmbeddedWithItsTypeSizeChecksumAndRelationship(): void
    {
        $pdf = self::attached(
            static fn(Document $doc): Document => $doc->attachFile(
                'data.csv',
                "a,b\n1,2\n",
                'text/csv',
                AttachmentRelationship::Data,
                'The figures',
            ),
        );

        self::assertSame(
            [1, 1, 1, 1],
            [
                preg_match('#/Type/EmbeddedFile/Subtype/text\#2Fcsv/Params<</Size 8/CreationDate\(D:2026#', $pdf),
                str_contains($pdf, '/CheckSum(' . md5("a,b\n1,2\n", binary: true) . ')') ? 1 : 0,
                preg_match(
                    '#/Type/Filespec/F\(data\.csv\)/UF\(data\.csv\)/Desc\(The figures\)/AFRelationship/Data/EF<</F \d+ 0 R/UF \d+ 0 R>>#',
                    $pdf,
                ),
                preg_match('#/AF\[\d+ 0 R\]#', $pdf),
            ],
        );
    }

    #[Test]
    public function aNameIsAttachedOnce(): void
    {
        $doc = new Document(new MemoryOutput());
        $doc->attachFile('notes.txt', 'notes');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A file named "notes.txt" is already attached.');
        $doc->attachFile('notes.txt', 'again');
    }

    #[Test]
    public function attachmentsAreListedByNameAlongsideNamedDestinations(): void
    {
        $pdf = self::attached(static function (Document $doc): void {
            $doc->addDestination('top', Destination::at(1, 0.0, 0.0));
            $doc->attachFile('zebra.txt', 'z')->attachFile('2024', 'year')->attachFile('apple.txt', 'a');
        });

        self::assertSame(
            [1, 1],
            [
                preg_match('#/Names<</Dests \d+ 0 R/EmbeddedFiles \d+ 0 R>>#', $pdf),
                preg_match('#<</Names\[\(2024\) \d+ 0 R \(apple\.txt\) \d+ 0 R \(zebra\.txt\) \d+ 0 R\]>>#', $pdf),
            ],
        );
    }

    #[Test]
    #[DataProvider('refusedProvider')]
    public function standardsThatDoNotAllowAttachmentsRefuseThem(DocumentOptions $options, string $error): void
    {
        $doc = new Document(new MemoryOutput(), $options);

        $this->expectException(ConformanceException::class);
        $this->expectExceptionMessage($error);
        $doc->attachFile('notes.txt', 'notes');
    }
}
