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
use WeakMap;

use function file_get_contents;
use function is_string;
use function md5;
use function realpath;
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

    /** @var WeakMap<SvgConverterInterface, array<string, PlaceableInterface>> conversions of files by path */
    private WeakMap $svgPaths;

    /** @var WeakMap<SvgConverterInterface, WeakMap<SvgFile, PlaceableInterface>> conversions of files without a path */
    private WeakMap $svgFiles;

    /** The converter for SVGs given none, made the first time one is. */
    private ?NativeConverter $nativeConverter = null;

    public function __construct(
        private readonly Objects $objects,
        private readonly ColourPolicyInterface $colour,
        private readonly DocumentOptions $options,
    ) {
        $this->svgPaths = new WeakMap();
        $this->svgFiles = new WeakMap();
    }

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
     * An SVG converted once for each converter: a file named by its path is read and converted
     * once however it is given, an SvgFile without a path once for as long as it lives.
     *
     * @throws IoException
     * @throws ParseException
     */
    public function svg(
        SvgFile|string $source,
        ?SvgConverterInterface $converter,
        SvgDocumentInterface $document,
    ): PlaceableInterface {
        $converter ??= $this->nativeConverter();
        $path      = is_string($source) ? $source : $source->path;
        if (null === $path && $source instanceof SvgFile) {
            $byFile                     = $this->svgFiles[$converter] ?? new WeakMap();
            $this->svgFiles[$converter] = $byFile;

            return $byFile[$source] ??= $converter->convert($source, $document);
        }
        $real   = realpath((string) $path);
        $key    = false === $real ? (string) $path : $real;
        $byPath = $this->svgPaths[$converter] ?? [];
        $done   = $byPath[$key] ?? null;
        if (null !== $done) {
            return $done;
        }
        $file = $source instanceof SvgFile
            ? $source
            : SvgFile::open($key, $this->options->limits);
        $byPath[$key]               = $converter->convert($file, $document);
        $this->svgPaths[$converter] = $byPath;

        return $byPath[$key];
    }

    private function load(string $data, ImageOptions $options, ?string $name): Image
    {
        return $this->images[md5($data) . $options->key()] ??= new Image(
            $this->objects,
            $this->colour,
            ImageReader::read($data, $this->options->limits, $options, $name)->withOptions($options),
        );
    }

    private function nativeConverter(): NativeConverter
    {
        return $this->nativeConverter ??= new NativeConverter();
    }
}
