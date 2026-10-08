<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Import\Lexer;
use Php2Pdf\Import\ObjectParser;
use Php2Pdf\Import\XrefParser;
use Php2Pdf\Import\XrefTable;
use Php2PdfTest\TestAsset\HostileFiles;
use Php2PdfTest\TestAsset\Import\CrossReferencePdf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use const PHP_INT_MAX;

#[Group('unit')]
final class XrefParserTest extends TestCase
{
    /** @return array<string, array{0: int}> */
    public static function badSizeProvider(): array
    {
        return [
            'more entries than the limit' => [5],
            'a negative count'            => [-1],
        ];
    }

    /** @throws ParseException */
    private static function read(string $pdf, int $maxEntries = PHP_INT_MAX): XrefTable
    {
        $lexer  = new Lexer($pdf);
        $parser = new XrefParser(
            $lexer,
            new ObjectParser($lexer),
            static fn(Stream $stream): string => $stream->data,
            $maxEntries,
        );
        $table = new XrefTable();
        $parser->readChain($parser->startXref(), $table);

        return $table;
    }

    #[Test]
    #[DataProvider('badSizeProvider')]
    public function aCrossReferenceStreamDeclaringAnUnusableEntryCountIsRejected(int $size): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('The PDF declares more than 2 cross-reference entries.');
        self::read(HostileFiles::xrefStream('[1 1 1]', $size), maxEntries: 2);
    }

    #[Test]
    public function aHybridFileAddsTheEntriesOfItsCrossReferenceStream(): void
    {
        self::assertTrue(self::read(CrossReferencePdf::hybrid())->has(4));
    }

    #[Test]
    public function anObjectNotInTheTableIsNotListed(): void
    {
        self::assertFalse(self::read(HostileFiles::pages(1))->has(4));
    }
}
