<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Override;
use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Color\ColourPolicyInterface;
use Php2Pdf\Core\FileReader;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Objects;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Exception\PermissionException;
use Php2Pdf\Image\Image;
use Php2Pdf\Image\ImageData;
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

use function filemtime;
use function filesize;
use function hash;
use function is_string;
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
    /** @var array<string, Image> images by content hash and options */
    private array $images = [];

    /** @var array<string, array{0: ImageData, 1: Reference}> images as read (without their samples, once written), and their XObjects, by content hash, page and conversion */
    private array $rasters = [];

    /** @var array<string, array{0: int, 1: int, 2: string}> image files by real path: modification time, size and content hash */
    private array $hashes = [];

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

    /** A file by its real path, an opened file without one by the object, for each way of treating hidden layers. */
    private static function importKey(PdfFile|string $source, HiddenLayers $hiddenLayers): string
    {
        if ($source instanceof PdfFile && null === $source->path) {
            return 'object#' . spl_object_id($source) . "#{$hiddenLayers->name}";
        }
        $path = $source instanceof PdfFile ? $source->path : $source;
        $real = realpath($path);

        return (false === $real ? $path : $real) . "#{$hiddenLayers->name}";
    }

    /** What reading an image depends on: its content, the page, and whether a lossy conversion is accepted. */
    private static function rasterKey(string $hash, ImageOptions $options): string
    {
        return sprintf('%s/%d/%d', $hash, $options->page, (int) $options->allowLossyConversion);
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

    /**
     * An image file, read again only when it has changed since it was last read.
     *
     * @throws IoException
     * @throws PdfException
     */
    public function image(string $path, ImageOptions $options): Image
    {
        $real  = realpath($path);
        $stamp = false === $real ? null : [(int) filemtime($real), (int) filesize($real)];
        $known = false === $real ? null : $this->hashes[$real] ?? null;
        if (null !== $known && null !== $stamp && [$known[0], $known[1]] === $stamp) {
            $raster = $this->rasters[self::rasterKey($known[2], $options)] ?? null;
            if (null !== $raster) {
                return $this->placed($known[2], $raster, $options);
            }
        }
        $data = FileReader::read($path, 'image file');
        $hash = hash('xxh128', $data);
        if (false !== $real && null !== $stamp) {
            $this->hashes[$real] = [$stamp[0], $stamp[1], $hash];
        }

        return $this->load($data, $hash, $options, $path);
    }

    /** @throws PdfException */
    public function imageFromData(string $data, ImageOptions $options): Image
    {
        return $this->load($data, hash('xxh128', $data), $options, null);
    }

    /**
     * A PDF file imported once: a path is looked up before the file is opened, so importing it
     * again does not parse it again.
     *
     * @throws IoException
     * @throws ParseException
     * @throws PermissionException when an encrypted file needs a password or forbids importing
     */
    public function import(PdfFile|string $source, HiddenLayers $hiddenLayers): ImportedDocument
    {
        $key  = self::importKey($source, $hiddenLayers);
        $done = $this->imports[$key] ?? null;
        if (null !== $done) {
            return $done;
        }
        $file = $source instanceof PdfFile ? $source : PdfFile::open($source, $this->options->limits);

        return $this->imports[$key] = new ImportedDocument($this->objects, $this->colour, $file, $hiddenLayers);
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

    /**
     * An image from its content, read and written once for each page and conversion, and placed
     * once for each set of options.
     *
     * @throws PdfException
     */
    private function load(string $data, string $hash, ImageOptions $options, ?string $name): Image
    {
        $key    = self::rasterKey($hash, $options);
        $raster = $this->rasters[$key] ?? null;
        if (null === $raster) {
            $read = ImageReader::read(
                $data,
                $this->options->limits,
                $options,
                $name,
                $this->options->imageDecoders,
            );
            $raster              = [$read->withoutSamples(), Image::write($this->objects, $read)];
            $this->rasters[$key] = $raster;
        }

        return $this->placed($hash, $raster, $options);
    }

    private function nativeConverter(): NativeConverter
    {
        return $this->nativeConverter ??= new NativeConverter();
    }

    /**
     * The image placed with its options, sharing the XObject of its pixels.
     *
     * @param array{0: ImageData, 1: Reference} $raster
     *
     * @throws PdfException
     */
    private function placed(string $hash, array $raster, ImageOptions $options): Image
    {
        return $this->images[$hash . $options->key()] ??= new Image(
            $this->objects,
            $this->colour,
            $raster[0]->withOptions($options),
            $raster[1],
        );
    }
}
