<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Import\ObjectCopier;
use Php2Pdf\Import\OptionalContent;
use Php2Pdf\Import\PdfReader;
use Php2PdfTest\TestAsset\LayeredPdf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

/** Merging a source's layers, when only Artwork and Guides (objects 5 and 6) were copied. */
#[Group('unit')]
final class OptionalContentTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function unusableAutoStateProvider(): array
    {
        return [
            'a state that is not a dictionary' => ['<< /AS [42] >>'],
            'a state over groups not copied'   => ['<< /AS [<< /Event /View /OCGs [7 0 R] >>] >>'],
        ];
    }

    /** @return array<mixed> */
    private static function array(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    private static function dictionary(mixed $value): Dictionary
    {
        self::assertInstanceOf(Dictionary::class, $value);

        return $value;
    }

    /** The /OCProperties written after adding the source's layers $times times. */
    private static function properties(string $config, int $times): Dictionary
    {
        $reader = PdfReader::fromData(LayeredPdf::make($config));
        $copier = new ObjectCopier($reader, (new Document(new MemoryOutput()))->objects());
        $copier->copy(new Reference(5));
        $copier->copy(new Reference(6));
        $layers = new OptionalContent();
        for ($i = 0; $i < $times; ++$i) {
            $layers->add($reader, $copier);
        }
        $catalog = new Dictionary();
        $layers->write($catalog);

        return self::dictionary($catalog->get('OCProperties'));
    }

    #[Test]
    public function addingTheSameSourceTwiceListsEachGroupOnce(): void
    {
        self::assertSame(2, count(self::array(self::properties(LayeredPdf::GUIDES_OFF, times: 2)->get('OCGs'))));
    }

    #[Test]
    #[DataProvider('unusableAutoStateProvider')]
    public function unusableAutomaticStatesAreLeftOut(string $config): void
    {
        self::assertNull(self::dictionary(self::properties($config, times: 1)->get('D'))->get('AS'));
    }
}
