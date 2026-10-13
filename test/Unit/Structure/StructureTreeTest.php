<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Structure;

use Override;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Objects;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Core\Writer;
use Php2Pdf\Structure\StructureRole;
use Php2Pdf\Structure\StructureTree;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;

/** A document's structure tree, its elements in the order their content is drawn, and its parent tree (#35). */
#[Group('unit')]
final class StructureTreeTest extends TestCase
{
    private MemoryOutput $out;
    private Objects $objects;
    private StructureTree $tree;

    #[Test]
    public function anAnnotationIsAnObjectReferenceUnderItsElementWithAParentTreeKey(): void
    {
        $page       = $this->objects->allocate();
        $marks      = $this->tree->page($page);
        $link       = $this->tree->element(StructureRole::Link, $this->tree->root);
        $annotation = $this->objects->allocate();
        $key        = $this->tree->annotation($link, $page, $annotation);
        $this->write();

        self::assertSame(
            [0, 1, 1, 1],
            [
                $marks->key,
                $key,
                preg_match(
                    "#/S/Link/P \\d+ 0 R/Pg {$page->id} 0 R/K\\[<</Type/OBJR/Obj {$annotation->id} 0 R/Pg {$page->id} 0 R>>\\]#",
                    $this->out->contents(),
                ),
                preg_match('#/ParentTree \d+ 0 R/ParentTreeNextKey 2>>#', $this->out->contents()),
            ],
        );
    }

    #[Test]
    public function anElementJoinsItsParentWhenItsFirstContentIsDrawn(): void
    {
        $marks     = $this->tree->page($this->objects->allocate());
        $paragraph = $this->tree->element(StructureRole::Paragraph, $this->tree->root);
        $link      = $this->tree->element(StructureRole::Link, $paragraph);
        $marks->mark($paragraph);
        $marks->mark($link);
        $marks->mark($paragraph);
        $this->write();

        self::assertSame(1, preg_match('#/S/P/P \d+ 0 R/Pg \d+ 0 R/K\[0 \d+ 0 R 2\]#', $this->out->contents()));
    }

    #[Test]
    public function anElementNothingIsDrawnInIsLeftOut(): void
    {
        $this->tree->element(StructureRole::Figure, $this->tree->root, 'Nothing drawn');
        $this->write();

        self::assertSame(0, preg_match('#/S/Figure#', $this->out->contents()));
    }

    #[Test]
    public function anEmptyElementJoinsItsParentWhenAttached(): void
    {
        $row  = $this->tree->element(StructureRole::TableRow, $this->tree->root);
        $cell = $this->tree->element(StructureRole::TableData, $row);
        $cell->attach();
        $cell->attach();
        $this->write();

        self::assertSame(
            [1, 1],
            [
                preg_match('#/S/TD/P \d+ 0 R/K\[\]>>#', $this->out->contents()),
                preg_match('#/S/TR/P \d+ 0 R/K\[\d+ 0 R\]>>#', $this->out->contents()),
            ],
        );
    }

    #[Test]
    public function contentOnAnotherPageIsAMarkedContentReference(): void
    {
        $first     = $this->objects->allocate();
        $second    = $this->objects->allocate();
        $paragraph = $this->tree->element(StructureRole::Paragraph, $this->tree->root);
        $this->tree->page($first)->mark($paragraph);
        $this->tree->page($second)->mark($paragraph);
        $this->write();

        self::assertSame(
            1,
            preg_match(
                "#/Pg {$first->id} 0 R/K\\[0 <</Type/MCR/Pg {$second->id} 0 R/MCID 0>>\\]#",
                $this->out->contents(),
            ),
        );
    }

    #[Test]
    public function eachPagesMarksAreListedInTheParentTreeByMcid(): void
    {
        $marks = $this->tree->page($this->objects->allocate());
        $h1    = $this->tree->element(StructureRole::H1, $this->tree->root);
        $p     = $this->tree->element(StructureRole::Paragraph, $this->tree->root);
        $marks->mark($h1);
        $marks->mark($p);
        $marks->mark($h1);
        $this->write();

        self::assertSame(
            1,
            preg_match('#<</Nums\[0 \[(\d+) 0 R (\d+) 0 R \1 0 R\]\]>>#', $this->out->contents()),
        );
    }

    #[Test]
    public function theAlternateTextAndAttributesAreWritten(): void
    {
        $figure = $this->tree->element(
            StructureRole::Figure,
            $this->tree->root,
            'A chart',
            Dictionary::of(['O' => new Name('Layout')]),
        );
        $this->tree->page($this->objects->allocate())->mark($figure);
        $this->write();

        self::assertSame(1, preg_match('#/K\[0\]/Alt\(A chart\)/A<</O/Layout>>#', $this->out->contents()));
    }

    #[Test]
    public function theRootIsTheDocumentElement(): void
    {
        $root = $this->write();

        self::assertSame(
            1,
            preg_match(
                "#{$root->id} 0 obj\\n<</Type/StructTreeRoot/K (\\d+) 0 R/ParentTree \\d+ 0 R/ParentTreeNextKey 0>>#",
                $this->out->contents(),
            ),
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->out     = new MemoryOutput();
        $this->objects = new Objects(new Writer($this->out, compress: false));
        $this->tree    = new StructureTree($this->objects);
    }

    private function write(): Reference
    {
        $root = $this->tree->write();
        $this->objects->finalize();

        return $root;
    }
}
