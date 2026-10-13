<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Canvas;

use LogicException;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Structure\StructureRole;
use Php2PdfTest\TestAsset\TaggedDocument;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;

/** Content marked as structure elements or artifacts on a tagged page (#35). */
#[Group('unit')]
final class TaggingTest extends TestCase
{
    /** The output of a tagged document whose one page the closure draws on. */
    private static function drawn(callable $draw): string
    {
        [$doc, $out] = TaggedDocument::open();
        $draw($doc->addPage()->canvas());
        $doc->close();

        return $out->contents();
    }

    #[Test]
    public function aLinkAreaOutsideAnElementIsALinkOfItsOwn(): void
    {
        $pdf = self::drawn(static function (Canvas $canvas): void {
            $canvas->linkArea(new Box(0.0, 0.0, 10.0, 10.0), 'https://example.com/');
        });

        self::assertSame(
            1,
            preg_match('#/S/Link/P \d+ 0 R/Pg \d+ 0 R/K\[<</Type/OBJR/Obj \d+ 0 R/Pg \d+ 0 R>>\]#', $pdf),
        );
    }

    #[Test]
    public function aNestedElementSplitsItsParentsSequence(): void
    {
        $pdf = self::drawn(static function (Canvas $canvas): void {
            $canvas->tag(StructureRole::Section, static function (Canvas $canvas): void {
                $canvas->fillRect(0.0, 0.0, 10.0, 10.0);
                $canvas->tag(StructureRole::Paragraph, static function (Canvas $canvas): void {
                    $canvas->fillRect(0.0, 0.0, 5.0, 5.0);
                });
                $canvas->fillRect(5.0, 5.0, 5.0, 5.0);
            });
        });

        self::assertSame(['/Sect 0', 'EMC', '/P 1', 'EMC', '/Sect 2', 'EMC'], TaggedDocument::marks($pdf));
    }

    #[Test]
    public function aSequenceIsClosedWhenTheDrawingThrows(): void
    {
        [$doc] = TaggedDocument::open();
        $canvas = $doc->addPage()->canvas();
        try {
            $canvas->tag(StructureRole::Paragraph, static function (Canvas $canvas): void {
                $canvas->fillRect(0.0, 0.0, 10.0, 10.0);

                throw new LogicException('Drawing failed');
            });
        } catch (LogicException) {
            $canvas->fillRect(0.0, 0.0, 5.0, 5.0);
        }

        self::assertSame(['/P 0', 'EMC', 'Artifact', 'EMC'], TaggedDocument::marks($canvas->finish()));
    }

    #[Test]
    public function aTemplateIsNotTagged(): void
    {
        [$doc] = TaggedDocument::open();
        $template = $doc->template(new Dimensions(10.0, 10.0));
        $template->canvas()->tag(StructureRole::Figure, static function (Canvas $canvas): void {
            $canvas->fillRect(0.0, 0.0, 10.0, 10.0);
        });

        self::assertSame([], TaggedDocument::marks($template->canvas()->finish()));
    }

    #[Test]
    public function contentInsideAnElementIsMarkedWithItsMcid(): void
    {
        $pdf = self::drawn(static function (Canvas $canvas): void {
            $canvas->tag(
                StructureRole::Figure,
                static function (Canvas $canvas): void {
                    $canvas->fillRect(0.0, 0.0, 10.0, 10.0);
                },
                'A square',
            );
        });

        self::assertSame(
            [1, 1],
            [
                preg_match("#/Figure <</MCID 0>> BDC\n0 0 10 10 re\nf\nEMC\n#", $pdf),
                preg_match('#/S/Figure/P \d+ 0 R/Pg \d+ 0 R/K\[0\]/Alt\(A square\)#', $pdf),
            ],
        );
    }

    #[Test]
    public function contentOutsideElementsIsAnArtifact(): void
    {
        $pdf = self::drawn(static function (Canvas $canvas): void {
            $canvas->fillRect(0.0, 0.0, 10.0, 10.0)->fillRect(10.0, 10.0, 10.0, 10.0);
        });

        self::assertSame(1, preg_match("#/Artifact BMC\n0 0 10 10 re\nf\n10 10 10 10 re\nf\nEMC\n#", $pdf));
    }

    #[Test]
    public function marksSwitchWhatIsDrawnOnlyWhenItChanges(): void
    {
        [$doc] = TaggedDocument::open();
        $canvas    = $doc->addPage()->canvas();
        $paragraph = $canvas->element(StructureRole::Paragraph);
        $canvas->within(null, static function (Canvas $canvas) use ($paragraph): void {
            $canvas->mark($paragraph)->fillRect(0.0, 0.0, 1.0, 1.0);
            $canvas->mark($paragraph)->fillRect(1.0, 1.0, 1.0, 1.0);
            $canvas->mark(null)->fillRect(2.0, 2.0, 1.0, 1.0);
        });

        self::assertSame(['/P 0', 'EMC', 'Artifact', 'EMC'], TaggedDocument::marks($canvas->finish()));
    }

    #[Test]
    public function onAnUntaggedPageContentIsDrawnAsItIs(): void
    {
        $out    = new MemoryOutput();
        $doc    = new Document($out);
        $canvas = $doc->addPage()->canvas();
        $canvas->tag(StructureRole::Paragraph, static function (Canvas $canvas): void {
            $canvas->fillRect(0.0, 0.0, 10.0, 10.0);
        });
        $canvas->mark(null);

        self::assertSame(
            ["0 0 10 10 re\nf\n", null, false],
            [$canvas->finish(), $canvas->element(StructureRole::Paragraph), $canvas->isTagged()],
        );
    }
}
