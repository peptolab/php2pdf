<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Import\PdfReader;
use Php2PdfTest\TestAsset\HostileFiles;
use Php2PdfTest\TestAsset\Import\CrossReferencePdf;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function gzcompress;

#[Group('unit')]
final class PdfReaderTest extends TestCase
{
    #[Test]
    public function anObjectMissingFromItsObjectStreamReadsAsNull(): void
    {
        $reader = PdfReader::fromData(CrossReferencePdf::brokenObjectStreamHeader());
        $reader->object(6);

        self::assertNull($reader->object(7));
    }

    #[Test]
    public function anObjectStreamDoesNotOverrideAnObjectTheCrossReferencePlacesElsewhere(): void
    {
        $reader = PdfReader::fromData(CrossReferencePdf::brokenObjectStreamHeader());
        $reader->object(6);

        self::assertInstanceOf(Dictionary::class, $reader->object(3));
    }

    #[Test]
    public function anObjectStreamHeaderThatBreaksOffStillYieldsTheObjectsBeforeTheBreak(): void
    {
        self::assertSame('six', PdfReader::fromData(CrossReferencePdf::brokenObjectStreamHeader())->object(6));
    }

    #[Test]
    public function anOffsetPastTheEndOfTheFileIsRepaired(): void
    {
        self::assertSame(1, count(PdfReader::fromData(CrossReferencePdf::offsetPastTheEnd())->pages()));
    }

    #[Test]
    public function decodeParametersAreResolvedBeforeDecoding(): void
    {
        $stream = new Stream(
            Dictionary::of([
                'Filter'      => new Name('FlateDecode'),
                'DecodeParms' => Dictionary::of(['Predictor' => 1]),
            ]),
            (string) gzcompress(data: 'hello'),
            compress: false,
        );

        self::assertSame('hello', PdfReader::fromData(HostileFiles::pages(1))->decode($stream));
    }

    #[Test]
    public function imageCodecDataIsLeftEncoded(): void
    {
        $stream = new Stream(Dictionary::of(['Filter' => new Name('DCTDecode')]), 'jpeg bytes', compress: false);

        self::assertSame('jpeg bytes', PdfReader::fromData(HostileFiles::pages(1))->decode($stream));
    }
}
