<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Import;

use PhpPdf\Color\IccProfile;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Document\PdfX;
use PhpPdf\Geometry\Box;
use PhpPdf\Import\PdfFile;
use PhpPdf\Import\PdfReader;
use PhpPdfTest\Support\LayeredPdf;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function is_array;
use function str_pad;
use function str_repeat;

/** Layers of imported pages keep their default state (#21). */
#[Group('unit')]
final class OptionalContentTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: mixed}> */
    public static function configurationProvider(): array
    {
        $as = '/AS [<< /Event /Print /Category [/Print] /OCGs [6 0 R 7 0 R] >>]';

        return [
            'groups the page uses'       => [LayeredPdf::GUIDES_OFF, 'OCGs', ['Artwork', 'Guides']],
            'groups switched off'        => [LayeredPdf::GUIDES_OFF, 'OFF', ['Guides']],
            'base state off'             => ['<< /BaseState /OFF /ON [5 0 R] >>', 'OFF', ['Guides']],
            'everything on'              => ['<< >>', 'OFF', null],
            'labelled panel order'       => [LayeredPdf::GUIDES_OFF, 'Order', [['Layers', 'Artwork', 'Guides']]],
            'order of unused groups'     => ['<< /Order [5 0 R [(Spare) 7 0 R]] >>', 'Order', ['Artwork']],
            'groups as order by default' => ['<< >>', 'Order', ['Artwork', 'Guides']],
            'locked groups'              => ['<< /Locked [6 0 R 7 0 R] >>', 'Locked', ['Guides']],
            'radio-button groups'        => [
                '<< /RBGroups [[5 0 R 6 0 R] [7 0 R]] >>',
                'RBGroups',
                [['Artwork', 'Guides']],
            ],
            'automatic states'           => ["<< {$as} >>", 'AS', [['Print', ['Print'], ['Guides']]]],
            'configuration name'         => ['<< >>', 'Name', 'Default'],
        ];
    }

    /** An entry of the output's default configuration (or the group list), with groups by name. */
    private static function layers(string $pdf, string $key): mixed
    {
        $reader     = PdfFile::fromData($pdf)->reader();
        $properties = $reader->dict($reader->catalog(), 'OCProperties');
        self::assertNotNull($properties);
        $source = 'OCGs' === $key ? $properties : $reader->dict($properties, 'D');
        self::assertNotNull($source);

        return self::named($reader, $source->get($key));
    }

    private static function named(PdfReader $reader, mixed $value): mixed
    {
        if ($value instanceof Reference) {
            $group = $reader->resolve($value);
            self::assertInstanceOf(Dictionary::class, $group);

            return $group->get('Name');
        }
        if ($value instanceof Dictionary) {
            return [
                self::named($reader, $value->get('Event')),
                self::named($reader, $value->get('Category')),
                self::named($reader, $value->get('OCGs')),
            ];
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = self::named($reader, $item);
            }

            return $out;
        }

        return $value instanceof Name ? $value->value : $value;
    }

    #[Test]
    #[DataProvider('configurationProvider')]
    public function carriesTheSourceConfiguration(string $config, string $key, mixed $expected): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(compress: false));
        $doc->addPage(PageSize::A4)->place(
            $doc->import(PdfFile::fromData(LayeredPdf::make($config)))->page(1),
            new Box(0.0, 0.0, 100.0, 100.0),
        );
        $doc->close();

        self::assertSame($expected, self::layers($out->contents(), $key));
    }

    #[Test]
    public function leavesAutomaticStatesOutOfPdfX(): void
    {
        $out     = new MemoryOutput();
        $profile = new IccProfile(str_pad(str_repeat("\0", times: 16) . 'CMYK', length: 128, pad_string: "\0"), 'test');
        $doc     = new Document($out, new DocumentOptions(
            compress: false,
            pdfx: PdfX::X4,
        ));
        $doc->setOutputIntent($profile, 'Test condition');
        $doc->info()->setTitle('Layers');
        $doc->addPage(PageSize::A4)->place(
            $doc->import(PdfFile::fromData(LayeredPdf::make(
                '<< /AS [<< /Event /Print /Category [/Print] /OCGs [6 0 R] >>] >>',
            )))->page(1),
            new Box(0.0, 0.0, 100.0, 100.0),
        );
        $doc->close();

        self::assertNull(self::layers($out->contents(), 'AS'));
    }

    #[Test]
    public function writesNoLayersForImportsThatAreNotPlaced(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(compress: false));
        $doc->import(PdfFile::fromData(LayeredPdf::make()));
        $doc->addPage(PageSize::A4);
        $doc->close();

        self::assertStringNotContainsString('/OCProperties', $out->contents());
    }

    #[Test]
    public function writesNoLayersForPagesWithout(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(compress: false));
        $doc->addPage(PageSize::A4)->place(
            $doc->import(PdfTools::assetPath('pdf/cairo.pdf'))->page(1),
            new Box(0.0, 0.0, 100.0, 100.0),
        );
        $doc->close();

        self::assertStringNotContainsString('/OCProperties', $out->contents());
    }
}
