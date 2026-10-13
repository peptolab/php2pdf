<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Core\Limits;
use Php2Pdf\Import\PdfFile;
use Php2Pdf\Import\StructureNode;
use Php2Pdf\Import\StructureReader;
use Php2PdfTest\TestAsset\HostileFiles;
use Php2PdfTest\TestAsset\LayeredPdf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function is_int;

/** The part of a tagged file's structure tree an imported page's content belongs to (#35). */
#[Group('unit')]
final class StructureReaderTest extends TestCase
{
    /** @return array<string, array{0: list<string>}> files whose first page brings no structure */
    public static function unstructuredProvider(): array
    {
        $page = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /StructParents 0 >>';

        return [
            'no structure tree'        => [[
                '<< /Type /Catalog /Pages 2 0 R >>',
                '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                $page,
            ]],
            'a page without a key'     => [[
                '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 4 0 R >>',
                '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>',
                '<< /Type /StructTreeRoot /ParentTree << /Nums [] >> >>',
            ]],
            'a key the tree lacks'     => [[
                '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 4 0 R >>',
                '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                $page,
                '<< /Type /StructTreeRoot /ParentTree << /Kids [5 0 R (no node)] >> >>',
                '<< /Limits [3 9] /Nums [3 []] >>',
            ]],
            'a leaf without the key'   => [[
                '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 4 0 R >>',
                '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                $page,
                '<< /Type /StructTreeRoot /ParentTree << /Nums [5 []] >> >>',
            ]],
            'content owned by nothing' => [[
                '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 4 0 R >>',
                '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                $page,
                '<< /Type /StructTreeRoot /ParentTree << /Nums [0 [null]] >> >>',
            ]],
            'owners outside the tree'  => [[
                '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 4 0 R >>',
                '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                $page,
                '<< /Type /StructTreeRoot /K [] /ParentTree << /Nums [0 [5 0 R]] >> >>',
                '<< /Type /StructElem /S /P /Pg 3 0 R /K 0 >>',
            ]],
        ];
    }

    /**
     * A node as a list of its type, the entries it brings and its kids.
     *
     * @return array{0: string, 1: list<string>, 2: list<mixed>}
     */
    private static function shape(StructureNode $node): array
    {
        $kids = [];
        foreach ($node->kids as $kid) {
            $kids[] = is_int($kid) ? $kid : self::shape($kid);
        }

        return [$node->type, array_keys($node->entries->all()), $kids];
    }

    /**
     * A tagged file of two pages: a Document holding a heading mapped to H1, an unmapped type
     * with a span, a reference to marked content on the page and others it leaves out, a
     * paragraph on the second page, and an element mapped round in a loop that lists itself.
     */
    private static function tagged(): string
    {
        return HostileFiles::pdf([
            '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 5 0 R >>',
            '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /StructParents 0 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /StructParents 7 >>',
            '<< /Type /StructTreeRoot /K 6 0 R /ParentTree 7 0 R /RoleMap << /Heading /H1 /Loop /Round /Round /Loop >> >>',
            '<< /Type /StructElem /S /Document /P 5 0 R /K [8 0 R 9 0 R 10 0 R 11 0 R] >>',
            '<< /Kids [13 0 R 16 0 R] >>',
            '<< /Type /StructElem /S /Heading /P 6 0 R /Pg 3 0 R /K 0 /Alt (Title) /ID (h1) >>',
            '<< /Type /StructElem /S /Custom /P 6 0 R /Pg 3 0 R /K [14 0 R << /Type /MCR /Pg 3 0 R /MCID 2 >>'
                . ' << /Type /MCR /Pg 3 0 R /Stm 15 0 R /MCID 3 >> << /Type /OBJR /Obj 15 0 R /Pg 3 0 R >> (junk)] >>',
            '<< /Type /StructElem /S /P /P 6 0 R /Pg 4 0 R /K 0 >>',
            '<< /Type /StructElem /S /Loop /P 6 0 R /Pg 3 0 R /K [4 9 11 0 R] >>',
            '<< /Limits [0 0] /Nums [0 [8 0 R 14 0 R 9 0 R null 11 0 R]] >>',
            '<< /Limits [7 7] /Nums [7 [10 0 R]] >>',
            '<< /Type /StructElem /S /Span /P 9 0 R /Pg 3 0 R /K 1 /Lang (en) >>',
            LayeredPdf::stream(''),
            '<< /Kids [12 0 R] >>',
        ]);
    }

    #[Test]
    public function anotherPageBringsItsOwnElements(): void
    {
        $file = PdfFile::fromData(self::tagged());
        $node = (new StructureReader($file->reader()))->read($file->page(2));

        self::assertSame(['', [], [['P', [], [0]]]], null === $node ? null : self::shape($node));
    }

    #[Test]
    public function aPageBringsTheElementsItsContentBelongsTo(): void
    {
        $file = PdfFile::fromData(self::tagged());
        $node = (new StructureReader($file->reader()))->read($file->page(1));

        self::assertSame(
            [
                '',
                [],
                [
                    ['H1', ['Alt'], [0]],
                    ['Div', [], [['Span', ['Lang'], [1]], 2]],
                    ['Div', [], [4]],
                ],
            ],
            null === $node ? null : self::shape($node),
        );
    }

    /** @param list<string> $objects */
    #[Test]
    #[DataProvider('unstructuredProvider')]
    public function aPageWithoutStructureBringsNone(array $objects): void
    {
        $file = PdfFile::fromData(HostileFiles::pdf($objects));

        self::assertNull((new StructureReader($file->reader()))->read($file->page(1)));
    }

    #[Test]
    public function aParentTreeDeeperThanTheLimitsIsNotFollowed(): void
    {
        $file = PdfFile::fromData(HostileFiles::pdf([
            '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 4 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /StructParents 0 >>',
            '<< /Type /StructTreeRoot /K 5 0 R /ParentTree << /Kids [6 0 R] >> >>',
            '<< /Type /StructElem /S /P /P 4 0 R /Pg 3 0 R /K 0 >>',
            '<< /Kids [7 0 R] >>',
            '<< /Kids [8 0 R] >>',
            '<< /Kids [9 0 R] >>',
            '<< /Kids [10 0 R] >>',
            '<< /Nums [0 [5 0 R]] >>',
        ]), new Limits(maxObjectNesting: 3));

        self::assertNull((new StructureReader($file->reader()))->read($file->page(1)));
    }

    #[Test]
    public function aStructureDeeperThanTheLimitsIsCutWhereTheyEnd(): void
    {
        $file = PdfFile::fromData(HostileFiles::pdf([
            '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 4 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /StructParents 0 >>',
            '<< /Type /StructTreeRoot /K 5 0 R /ParentTree 9 0 R >>',
            '<< /Type /StructElem /S /Sect /P 4 0 R /K 6 0 R >>',
            '<< /Type /StructElem /S /Div /P 5 0 R /K 7 0 R >>',
            '<< /Type /StructElem /S /Span /P 6 0 R /K 8 0 R >>',
            '<< /Type /StructElem /S /P /P 7 0 R /Pg 3 0 R /K 0 >>',
            '<< /Nums [0 [8 0 R]] >>',
        ]), new Limits(maxObjectNesting: 3));
        $node = (new StructureReader($file->reader()))->read($file->page(1));

        self::assertSame(
            ['', [], [['Sect', [], [['Div', [], [['Span', [], [['P', [], []]]]]]]]]],
            null === $node ? null : self::shape($node),
        );
    }

    #[Test]
    public function theMcidsCanBeTakenOutOfContent(): void
    {
        $file = PdfFile::fromData(self::tagged());

        self::assertSame(
            ' /P BMC (a) Tj EMC /Span <</ActualText (x)>> BDC EMC /OC /L1 BDC EMC',
            (new StructureReader($file->reader()))->withoutMcids(
                '/P <</MCID 0>> BDC (a) Tj EMC /Span <</ActualText (x)>> BDC EMC /OC /L1 BDC EMC',
            ),
        );
    }
}
