<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Override;
use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Color\ColourPolicyInterface;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Objects;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Image\Image;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Image\ImageReader;
use Php2Pdf\Import\HiddenLayers;
use Php2Pdf\Import\ImportedDocument;
use Php2Pdf\Import\OptionalContent;
use Php2Pdf\Import\PdfFile;
use Php2Pdf\Svg\NativeConverter;
use Php2Pdf\Svg\SvgConverterInterface;
use Php2Pdf\Svg\SvgDocumentInterface;
use Php2Pdf\Svg\SvgFile;

use function file_get_contents;
use function md5;
use function spl_object_id;
use function sprintf;

/**
 * The images, imported PDFs and SVGs a document loads, each loaded and written once however
 * often it is asked for, and the layers imported pages bring, which the catalog lists.
 *
 * @internal
 */
final class Assets implements CatalogContributorInterface
{
    /** @var array<string, Image> */
    private array $images = [];

    /** @var array<string, ImportedDocument> */
    private array $imports = [];

    /** @var array<string, PlaceableInterface> */
    private array $svgs = [];

    public function __construct(
        private readonly Objects $objects,
        private readonly ColourPolicyInterface $colour,
        private readonly DocumentOptions $options,
    ) {}

    /**
     * Lists the layers the imported pages use, without automatic states under PDF/X.
     *
     * @throws ParseException
     */
    #[Override]
    public function contribute(Dictionary $catalog, array $pageRefs): void
    {
        $layers = new OptionalContent();
        foreach ($this->imports as $import) {
            $import->addOptionalContent($layers);
        }
        if (null !== $this->options->pdfx) {
            $layers->dropAutoStates();
        }
        $layers->write($catalog);
    }

    /** @throws IoException */
    public function image(string $path, ImageOptions $options): Image
    {
        $data = file_get_contents($path);
        if (false === $data) {
            throw new IoException(sprintf('Cannot read image file "%s".', $path));
        }

        return $this->load($data, $options, $path);
    }

    public function imageFromData(string $data, ImageOptions $options): Image
    {
        return $this->load($data, $options, null);
    }

    /** @throws ParseException */
    public function import(PdfFile|string $source, HiddenLayers $hiddenLayers): ImportedDocument
    {
        $file = $source instanceof PdfFile ? $source : PdfFile::open($source, $this->options->limits);
        $key  = ($file->path ?? 'object#' . spl_object_id($file)) . '#' . $hiddenLayers->name;

        return $this->imports[$key] ??= new ImportedDocument($this->objects, $this->colour, $file, $hiddenLayers);
    }

    /**
     * @throws IoException
     * @throws ParseException
     */
    public function svg(
        SvgFile|string $source,
        ?SvgConverterInterface $converter,
        SvgDocumentInterface $document,
    ): PlaceableInterface {
        $file      = $source instanceof SvgFile ? $source : SvgFile::open($source, $this->options->limits);
        $converter ??= new NativeConverter();
        $key       = ($file->path ?? 'object#' . spl_object_id($file))
        . '|'
        . $converter::class
        . '#'
        . spl_object_id($converter);

        return $this->svgs[$key] ??= $converter->convert($file, $document);
    }

    private function load(string $data, ImageOptions $options, ?string $name): Image
    {
        return $this->images[md5($data) . $options->key()] ??= new Image(
            $this->objects,
            $this->colour,
            ImageReader::read($data, $this->options->limits, $options, $name)->withOptions($options),
        );
    }
}
