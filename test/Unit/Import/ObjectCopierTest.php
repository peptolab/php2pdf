<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Override;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Import\CopyPolicy;
use Php2Pdf\Import\ObjectCopier;
use Php2Pdf\Import\PdfReader;
use Php2PdfTest\TestAsset\HostileFiles;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Copying follows references, except to pages, which would copy the whole source. */
#[Group('unit')]
final class ObjectCopierTest extends TestCase
{
    private ObjectCopier $copier;

    #[Test]
    public function anEntryReferringToAPageIsDropped(): void
    {
        $copy = $this->copier->copyDictionary(Dictionary::of([
            'P'    => new Reference(3),
            'Font' => new Reference(4),
        ]));

        self::assertSame([false, true], [$copy->has('P'), $copy->get('Font') instanceof Reference]);
    }

    #[Test]
    public function anObjectIsCopiedOnce(): void
    {
        self::assertSame($this->copier->copy(new Reference(4)), $this->copier->copy(new Reference(4)));
    }

    #[Test]
    public function aPageInAnArrayBecomesNullSoTheRestKeepTheirPlaces(): void
    {
        $copy = $this->copier->copyDictionary(Dictionary::of(['Kids' => [new Reference(2), new Reference(4)]]));

        self::assertEquals(
            [null, $this->copier->copyDictionary(Dictionary::of(['Font' => new Reference(4)]))->get('Font')],
            $copy->get('Kids'),
        );
    }

    #[Test]
    public function aPolicyCanSkipOtherTypes(): void
    {
        $policy = new CopyPolicy(['Font']);

        self::assertSame(
            [false, true, true],
            [
                $policy->follows(Dictionary::of(['Type' => new Name('Font')])),
                $policy->follows(Dictionary::of(['Type' => new Name('Page')])),
                $policy->follows(42),
            ],
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $source = PdfReader::fromData(HostileFiles::pdf([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 10 10] >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ]));
        $this->copier = new ObjectCopier($source, (new Document(new MemoryOutput()))->objects());
    }
}
