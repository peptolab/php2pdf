<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Import\AnnotationFlattener;
use Php2Pdf\Import\HiddenContent;
use Php2Pdf\Import\HiddenLayers;
use Php2Pdf\Import\PdfFile;
use Php2PdfTest\TestAsset\HostileFiles;
use Php2PdfTest\TestAsset\Import\AnnotatedPdf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;

/** The annotations of AnnotatedPdf's page, drawn and linked as flattening has them. */
#[Group('unit')]
final class AnnotationFlattenerTest extends TestCase
{
    /** What flattening draws after the page's content: the red, magenta, yellow and cyan squares. */
    private const string DRAWN =
        "q\n\nQ\n"
            . "q 4 0 0 4 20 20 cm /Annot0 Do Q\n"
            . "q 4 0 0 4 20 80 cm /Annot1 Do Q\n"
            . "q 4 0 0 4 120 80 cm /Annot2 Do Q\n"
            . "/OC /AnnotOC3 BDC q 4 0 0 4 20 140 cm /Annot3 Do Q EMC\n";

    /** @return array<string, array{0: string}> an annotation that prints but cannot be drawn */
    public static function undrawableProvider(): array
    {
        return [
            'no appearance'                      => ['<< /Subtype /Text /F 4 /Rect [0 0 10 10] >>'],
            'appearance states with none chosen' => [
                '<< /Subtype /Square /F 4 /Rect [0 0 10 10] /AP << /N << /On 14 0 R >> >> >>',
            ],
            'a rectangle of text'                => [
                '<< /Subtype /Square /F 4 /Rect [0 0 (x) 10] /AP << /N 14 0 R >> >>',
            ],
            'a rectangle of three numbers'       => ['<< /Subtype /Square /F 4 /Rect [0 0 10] /AP << /N 14 0 R >> >>'],
            'no rectangle'                       => ['<< /Subtype /Square /F 4 /AP << /N 14 0 R >> >>'],
            'an appearance with no area'         => [
                '<< /Subtype /Square /F 4 /Rect [0 0 10 10] /AP << /N 20 0 R >> >>',
            ],
            'an appearance with no box'          => [
                '<< /Subtype /Square /F 4 /Rect [0 0 10 10] /AP << /N 4 0 R >> >>',
            ],
            'a hidden link'                      => [
                '<< /Subtype /Link /F 2 /Rect [0 0 10 10] /A << /S /URI /URI (https://hidden/) >> >>',
            ],
        ];
    }

    private static function flattener(string $pdf, HiddenLayers $layers = HiddenLayers::Keep): AnnotationFlattener
    {
        $file   = PdfFile::fromData($pdf);
        $reader = $file->reader();

        return new AnnotationFlattener(
            $reader,
            $file->page(1),
            HiddenLayers::Remove === $layers ? HiddenContent::of($reader) : null,
        );
    }

    /** @return list<string> */
    private static function keys(mixed $dict): array
    {
        return $dict instanceof Dictionary ? array_keys($dict->all()) : [];
    }

    /**
     * The names in a resource dictionary of the resources.
     *
     * @return list<string>
     */
    private static function names(Dictionary $resources, string $kind): array
    {
        return self::keys($resources->get($kind));
    }

    #[Test]
    public function anAnnotationsListThatIsNoArrayHasNoAnnotations(): void
    {
        self::assertSame([[], []], [
            self::flattener(AnnotatedPdf::pdf(annots: '(none)'))->appearances(),
            self::flattener(AnnotatedPdf::pdf(annots: '(none)'))->links(),
        ]);
    }

    #[Test]
    #[DataProvider('undrawableProvider')]
    public function anAnnotationThatCannotBeDrawnIsLeftOut(string $annotation): void
    {
        [, $content] = self::flattener(AnnotatedPdf::pdf([$annotation]))->flatten(null, '');

        self::assertSame(self::DRAWN, $content);
    }

    #[Test]
    public function annotationsThatPrintAreDrawnFittedToTheirRectangles(): void
    {
        [, $content] = self::flattener(AnnotatedPdf::pdf())->flatten(null, '');

        self::assertSame(self::DRAWN, $content);
    }

    #[Test]
    public function annotationsWhoseLayerIsRemovedAreNotDrawn(): void
    {
        [, $content] = self::flattener(AnnotatedPdf::pdf(), HiddenLayers::Remove)->flatten(null, '');

        self::assertStringNotContainsString('AnnotOC', $content);
    }

    #[Test]
    public function aPageWithoutAnnotationsKeepsItsResourcesAndContent(): void
    {
        $resources = Dictionary::of(['ProcSet' => []]);

        self::assertSame(
            [$resources, 'content'],
            self::flattener(HostileFiles::pages(1))->flatten($resources, 'content'),
        );
    }

    #[Test]
    public function appearancesAreNamedAfterThePageResourcesTheyJoin(): void
    {
        $file = PdfFile::fromData(AnnotatedPdf::pdf(
            resources: '/XObject << /Annot0 14 0 R >> /Properties << /AnnotOC3 12 0 R >>',
        ));
        [$resources] = (new AnnotationFlattener($file->reader(), $file->page(1)))->flatten(
            $file->page(1)->resources(),
            '',
        );

        self::assertSame(
            [['Annot0', 'Annot0_2', 'Annot1', 'Annot2', 'Annot3'], ['AnnotOC3', 'AnnotOC3_2']],
            [self::names($resources, 'XObject'), self::names($resources, 'Properties')],
        );
    }

    #[Test]
    public function linksToOtherPlacesInTheSourceAreLeftOut(): void
    {
        $annots = '[11 0 R << /Subtype /Link /Rect [0 0 1 1] /A << /S /GoTo /D [3 0 R /Fit] >> >> (junk)]';

        self::assertSame([], self::flattener(AnnotatedPdf::pdf(annots: $annots))->links());
    }

    #[Test]
    public function linksToWebAddressesAreFoundByTheirRectangles(): void
    {
        self::assertEquals(
            [[new Box(140.0, 80.0, 40.0, 40.0), AnnotatedPdf::URI]],
            self::flattener(AnnotatedPdf::pdf())->links(),
        );
    }
}
