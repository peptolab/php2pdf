<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Core\Object\Name;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Import\ContentOperation;
use Php2Pdf\Import\ContentStreamParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function iterator_to_array;
use function strlen;
use function substr;

#[Group('unit')]
final class ContentStreamParserTest extends TestCase
{
    private static function operation(string $content, int $index): ContentOperation
    {
        return self::operations($content)[$index] ?? self::fail("The content has no operation {$index}.");
    }

    /** @return list<ContentOperation> */
    private static function operations(string $content): array
    {
        return iterator_to_array(
            (new ContentStreamParser($content, maxNesting: 32))->operations(),
            preserve_keys: false,
        );
    }

    #[Test]
    public function aKeyWithoutAValueEndsTheInlineImageEntries(): void
    {
        self::assertEquals(['W' => 1], self::operation('BI /W 1 /H ID x EI', 0)->inlineImage);
    }

    #[Test]
    public function anInlineImageCarriesItsEntriesAndSpansItsData(): void
    {
        $content   = "q BI /W 1 /H 1 /CS /RGB /BPC 8 ID \x00\xFF\x00 EI Q";
        $operation = self::operation($content, 1);

        self::assertEquals(
            [
                'BI',
                ['W' => 1, 'H' => 1, 'CS' => new Name('RGB'), 'BPC' => 8],
                " BI /W 1 /H 1 /CS /RGB /BPC 8 ID \x00\xFF\x00 EI",
            ],
            [
                $operation->operator,
                $operation->inlineImage,
                substr($content, $operation->start, $operation->end - $operation->start),
            ],
        );
    }

    #[Test]
    public function anInlineImageEndingTheStreamSpansToTheEnd(): void
    {
        $content = 'BI /W 1 ID data';

        self::assertSame(strlen($content), self::operation($content, 0)->end);
    }

    #[Test]
    public function anInlineImageEntryWithoutANameIsPassedOver(): void
    {
        self::assertEquals(['W' => 1], self::operation('BI 5 /W 1 ID x EI', 0)->inlineImage);
    }

    #[Test]
    public function anInlineImageThatCannotBeReadIsAnError(): void
    {
        $this->expectException(ParseException::class);

        self::operations('q BI /W ) ID x EI');
    }

    #[Test]
    public function anOperationSpansFromTheOneBeforeToItsOperator(): void
    {
        $content   = "q\n  /Gs1 gs Q";
        $operation = self::operation($content, 1);

        self::assertSame("\n  /Gs1 gs", substr($content, $operation->start, $operation->end - $operation->start));
    }

    #[Test]
    public function operationsComeInOrderWithTheirOperands(): void
    {
        $operations = self::operations('q 1 0 0 1 10 20 cm /F1 12 Tf [(A) -20 (B)] TJ Q');

        self::assertEquals(
            [
                ['q', []],
                ['cm', [1, 0, 0, 1, 10, 20]],
                ['Tf', [new Name('F1'), 12]],
                ['TJ', [['A', -20, 'B']]],
                ['Q', []],
            ],
            array_map(
                /** @return array{0: string, 1: list<mixed>} */
                static fn(ContentOperation $o): array => [$o->operator, $o->operands],
                $operations,
            ),
        );
    }

    #[Test]
    public function theWalkEndsAtATokenItCannotRead(): void
    {
        self::assertSame(['q'], array_column(self::operations('q ) Q'), column_key: 'operator'));
    }
}
