<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Import;

use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\Page;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Import\ImportedPage;
use Php2Pdf\Import\PdfFile;
use Php2Pdf\Structure\StructureRole;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\TextFormat;
use Php2PdfTest\TestAsset\PdfTools;
use Php2PdfTest\TestAsset\TaggedDocument;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function preg_match;
use function preg_match_all;
use function strpos;
use function substr;

/** An imported page of a tagged file bringing its structure where it is first placed (#35). */
#[Group('integration')]
final class ImportedStructureTest extends TestCase
{
    /** @return list<string> the contents of the output's forms, in the order they are written */
    private static function forms(string $pdf): array
    {
        $forms = [];
        $at    = 0;
        while (false !== ($at = strpos($pdf, needle: '/Subtype/Form', offset: $at))) {
            $start   = (int) strpos($pdf, needle: "stream\n", offset: $at) + 7;
            $end     = (int) strpos($pdf, needle: "\nendstream", offset: $start);
            $forms[] = substr($pdf, $start, $end - $start);
            $at      = $end;
        }

        return $forms;
    }

    /**
     * The output of a host document, tagged or untagged, once the closure placed the source's
     * page on its one page.
     *
     * @param callable(Page, ImportedPage): mixed $place
     */
    private static function placed(callable $place, string $host = 'tagged', string $source = 'tagged'): string
    {
        [$doc, $out] = 'tagged' === $host ? TaggedDocument::open() : self::untagged();
        $page = $doc->addPage();
        $place($page, $doc->import(PdfFile::fromData(self::source($source)))->page(1));
        $doc->close();

        return $out->contents();
    }

    /** A page with a heading, a paragraph and a figure, from a tagged document or an untagged one, as a file's bytes. */
    private static function source(string $kind): string
    {
        [$doc, $out] = 'tagged' === $kind ? TaggedDocument::open() : self::untagged();
        $font = $doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $doc->styles()->define('body', new TextStyle(
            font: $font,
            size: 11.0,
        ));
        $doc->styles()->define('heading', new TextStyle(
            size: 18.0,
            role: StructureRole::H1,
        ));
        $doc->addPage()->fitTextflow(
            $doc->textflow('<p style="heading">A heading</p><p>A paragraph.</p>', 'body', TextFormat::Markup),
            new Box(50.0, 600.0, 400.0, 200.0),
        );
        $doc->currentPage()?->canvas()->tag(
            StructureRole::Figure,
            static function (Canvas $canvas): void {
                $canvas->fillRect(50.0, 400.0, 100.0, 100.0);
            },
            'A square',
        );
        $doc->close();

        return $out->contents();
    }

    /** @return array{0: Document, 1: MemoryOutput} an untagged document written uncompressed */
    private static function untagged(): array
    {
        $out = new MemoryOutput();

        return [new Document($out, new DocumentOptions(compress: false)), $out];
    }

    #[Test]
    public function anUntaggedHostKeepsThePageAsItIs(): void
    {
        $pdf = self::placed(static function (Page $page, ImportedPage $imported): void {
            $page->place($imported, new Box(0.0, 0.0, 200.0, 300.0));
        }, host: 'untagged');

        self::assertSame(
            [0, 3],
            [preg_match('#StructTreeRoot#', $pdf), preg_match_all('#<</MCID \d+>> BDC#', self::forms($pdf)[0] ?? '')],
        );
    }

    #[Test]
    public function anUntaggedPageIsPlacedAsBefore(): void
    {
        $pdf = self::placed(static function (Page $page, ImportedPage $imported): void {
            $page->place($imported, new Box(0.0, 0.0, 200.0, 300.0));
            $page->place($imported, new Box(200.0, 0.0, 200.0, 300.0));
        }, source: 'untagged');

        self::assertSame([0, 1], [TaggedDocument::count($pdf, 'Sect'), count(self::forms($pdf))]);
    }

    #[Test]
    public function aPagePlacedAsAFigureBringsNoStructure(): void
    {
        $pdf = self::placed(static function (Page $page, ImportedPage $imported): void {
            $page->place($imported, new Box(0.0, 0.0, 200.0, 300.0), Fit::meet(), 'A page');
        });

        self::assertSame(
            [0, 1, 0],
            [
                TaggedDocument::count($pdf, 'Sect'),
                TaggedDocument::count($pdf, 'Figure'),
                preg_match('#MCID#', self::forms($pdf)[0] ?? ''),
            ],
        );
    }

    #[Test]
    public function aPagePlacedInATemplateFirstIsStillCopiedOnAPage(): void
    {
        $pdf = self::placed(static function (Page $page, ImportedPage $imported): void {
            $template = $page->document()->template(new Dimensions(200.0, 300.0));
            $template->canvas()->place($imported, new Box(0.0, 0.0, 200.0, 300.0));
            $page->place($template, new Box(0.0, 0.0, 200.0, 300.0));
            $page->place($imported, new Box(200.0, 0.0, 200.0, 300.0));
        });

        self::assertSame(
            [1, 1, 3],
            [
                TaggedDocument::count($pdf, 'Sect'),
                preg_match_all('#/Subtype/Form/.*?/StructParents \d+/#', $pdf),
                count(self::forms($pdf)),
            ],
        );
    }

    #[Test]
    public function laterPlacementsDrawAFormWhoseContentClaimsNoElement(): void
    {
        $pdf = self::placed(static function (Page $page, ImportedPage $imported): void {
            $page->place($imported, new Box(0.0, 0.0, 200.0, 300.0));
            $page->place($imported, new Box(200.0, 0.0, 200.0, 300.0));
        });
        $forms = self::forms($pdf);

        self::assertSame(
            [3, 3, 0, 1],
            [
                preg_match_all('#<</MCID \d+>> BDC#', $forms[0] ?? ''),
                preg_match_all('#/(H1|P|Figure) BMC#', $forms[1] ?? ''),
                preg_match('#MCID#', $forms[1] ?? ''),
                preg_match("#/Artifact BMC\n(?:(?!EMC)[^\n]*\n)*?q [^\n]+ cm /X2 Do Q\n#", $pdf),
            ],
        );
    }

    #[Test]
    public function theFirstPlacementCopiesTheStructureIntoASection(): void
    {
        $pdf = self::placed(static function (Page $page, ImportedPage $imported): void {
            $page->canvas()->tag(StructureRole::Section, static function () use ($page, $imported): void {
                $page->place($imported, new Box(0.0, 0.0, 200.0, 300.0));
            });
        });

        self::assertSame(
            [2, 1, 1, 1, 1, 1],
            [
                TaggedDocument::count($pdf, 'Sect'),
                preg_match('#/S/Figure/P \d+ 0 R/Pg \d+ 0 R/K\[<</Type/MCR[^>]+>>\]/Alt\(A square\)#', $pdf),
                preg_match('#/S/H1/P (\d+) 0 R/Pg (\d+) 0 R/K\[<</Type/MCR/Pg \2 0 R/Stm (\d+) 0 R/MCID 0>>\]#', $pdf),
                preg_match('#/S/P/P \d+ 0 R/Pg \d+ 0 R/K\[<</Type/MCR/Pg \d+ 0 R/Stm \d+ 0 R/MCID 1>>\]#', $pdf),
                preg_match('#/Subtype/Form/.*/StructParents 1/#', $pdf),
                preg_match("#\nq [^\n]+ cm /X1 Do Q\n#", $pdf),
            ],
        );
    }
}
