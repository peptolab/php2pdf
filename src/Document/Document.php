<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Closure;
use DateTimeImmutable;
use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Color\IccProfile;
use Php2Pdf\Core\ClockInterface;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Output\FileOutput;
use Php2Pdf\Core\Output\OutputInterface;
use Php2Pdf\Core\TextString;
use Php2Pdf\Core\Writer;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\LayoutException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Flow\FlowableInterface;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Font\FontRegistry;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Origin;
use Php2Pdf\Image\Image;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Image\ImageReader;
use Php2Pdf\Import\HiddenLayers;
use Php2Pdf\Import\ImportedDocument;
use Php2Pdf\Import\OptionalContent;
use Php2Pdf\Import\PdfFile;
use Php2Pdf\Svg\NativeConverter;
use Php2Pdf\Svg\SvgConverterInterface;
use Php2Pdf\Svg\SvgFile;
use Php2Pdf\Template\Template;
use Php2Pdf\Text\Hyphenation\Hyphenator;
use Php2Pdf\Text\InlineRegistry;
use Php2Pdf\Text\Style\StyleRegistry;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Textflow;
use Php2Pdf\Text\TextFormat;

use function array_values;
use function count;
use function file_get_contents;
use function is_string;
use function md5;
use function min;
use function spl_object_id;
use function sprintf;

/**
 * A PDF document being written. Pages are streamed to the output as soon as the next page starts,
 * so memory use stays flat for long documents; fonts and other deferred objects are written when
 * the document closes.
 *
 * @api
 */
final class Document
{
    private readonly Writer $writer;
    private readonly Reference $pagesRef;
    private readonly Info $info;
    private readonly DocumentOptions $options;
    private readonly DateTimeImmutable $createdAt;
    private readonly Objects $objects;
    private readonly PdfXRules $pdfx;

    /** @var list<Reference> */
    private array $pageRefs = [];

    /** @var list<DeferredInterface> */
    private array $deferred = [];

    /** @var list<string> extra XMP descriptions */
    private array $xmpDescriptions = [];

    /** @var array<string, ImportedDocument> path => imported file */
    private array $imports = [];

    /** @var array<string, PlaceableInterface> svg key => converted artwork */
    private array $svgs = [];

    /** @var list<Closure(Document): void> */
    private array $closing = [];

    /** @var array<string, Image> content hash => image */
    private array $images = [];

    private ?FontRegistry  $fonts  = null;
    private ?StyleRegistry $styles = null;

    private ?InlineRegistry $inlines    = null;
    private ?Page           $current    = null;
    private bool            $closed     = false;
    private ?Navigation     $navigation = null;

    public function __construct(OutputInterface|string $output, ?DocumentOptions $options = null)
    {
        $this->options = $options ?? new DocumentOptions();
        $this->info    = new Info();
        $this->writer  = new Writer(
            is_string($output) ? new FileOutput($output) : $output,
            $this->options->compress,
            $this->options->compressionLevel,
        );
        $this->createdAt = $this->options->clock->now();
        $this->writer->writeHeader($this->options->version);
        $this->pagesRef = $this->writer->allocate();
        $this->objects  = new Objects($this->writer);
        $this->pdfx     = new PdfXRules($this->options);
    }

    /**
     * Each page's frames, from flow()'s argument.
     *
     * @param Box|list<Box|list<Box>> $frames
     *
     * @return non-empty-list<non-empty-list<Box>>
     */
    private static function framesPerPage(Box|array $frames): array
    {
        $pages = [];
        foreach ($frames instanceof Box ? [$frames] : $frames as $entry) {
            $boxes = $entry instanceof Box ? [$entry] : array_values($entry);
            if ([] === $boxes) {
                throw new InvalidArgumentException('flow() needs at least one frame on every page.');
            }
            $pages[] = $boxes;
        }
        if ([] === $pages) {
            throw new InvalidArgumentException('flow() needs at least one box.');
        }

        return $pages;
    }

    /**
     * Registers a named destination, so links and bookmarks can point at it by name and other
     * documents can open this one at it.
     */
    public function addDestination(string $name, Destination $destination): self
    {
        $this->assertOpen();
        $this->navigation()->addDestination($name, $destination);

        return $this;
    }

    /**
     * Allocates a link annotation for a page; it is written when the document closes, once the
     * pages it may point at exist.
     *
     * @internal
     */
    public function addLinkAnnotation(Box $area, Destination|string $target, ?string $tooltip = null): Reference
    {
        return $this->navigation()->addLink($area, $target, $tooltip);
    }

    /**
     * Starts a new page. The previous page, if any, is finished and written out.
     */
    public function addPage(PageSize|Dimensions $size = PageSize::A4, ?bool $topDown = null): Page
    {
        $this->assertOpen();
        $this->finishCurrentPage();

        $dimensions    = $size instanceof PageSize ? $size->dimensions() : $size;
        $this->current = new Page($this, $dimensions, $topDown ?? $this->options->topDown, count($this->pageRefs) + 1);

        return $this->current;
    }

    /**
     * Adds an RDF description to the XMP packet, for conformance declarations such as PDF/X.
     *
     * @internal
     */
    public function addXmpDescription(string $rdf): void
    {
        $this->xmpDescriptions[] = $rdf;
    }

    /**
     * Refuses a resource made by another document, which would refer to objects this one never
     * writes.
     *
     * @internal
     */
    public function assertOwns(self $owner, string $what): void
    {
        if ($owner !== $this) {
            throw new InvalidArgumentException(sprintf(
                '%s belongs to the document that created it and cannot be drawn into another.',
                $what,
            ));
        }
    }

    /**
     * The colour space transparency groups blend in: the output intent's space under PDF/X,
     * otherwise RGB.
     *
     * @internal
     */
    public function blendingColorSpace(): Name|Reference
    {
        if (null === $this->options->pdfx) {
            return new Name('DeviceRGB');
        }

        return match ($this->pdfx->intent()->components ?? 3) {
            1       => new Name('DeviceGray'),
            4       => new Name('DeviceCMYK'),
            default => $this->defaultRgb() ?? new Name('DeviceRGB'),
        };
    }

    public function clock(): ClockInterface
    {
        return $this->options->clock;
    }

    /**
     * Runs the onClose() callbacks, then finishes the current page and writes the page tree,
     * catalog, metadata and trailer.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->assertOpen();
        foreach ($this->closing as $callback) {
            $callback($this);
        }
        $this->closing = [];
        $this->finishCurrentPage();
        if ([] === $this->pageRefs) {
            throw new PdfException('A document needs at least one page.');
        }
        foreach ($this->pdfx->complete($this->info->title()) as $description) {
            $this->xmpDescriptions[] = $description;
        }

        foreach ($this->deferred as $deferred) {
            $deferred->finalize($this);
        }

        $this->writer->write($this->pagesRef, Dictionary::of([
            'Type'  => new Name('Pages'),
            'Kids'  => $this->pageRefs,
            'Count' => count($this->pageRefs),
        ]));

        $catalog = Dictionary::of(['Type' => new Name('Catalog'), 'Pages' => $this->pagesRef]);
        $this->navigation?->write($catalog, $this->pageRefs);
        $layers = new OptionalContent();
        foreach ($this->imports as $import) {
            $import->addOptionalContent($layers);
        }
        $layers->write($catalog, $this->options->pdfx);
        if ($this->options->xmp) {
            $xmp = new Xmp($this->info, $this->options->producer, $this->createdAt);
            foreach ($this->xmpDescriptions as $description) {
                $xmp->addDescription($description);
            }
            $metadata = new Stream(
                Dictionary::of(['Type' => new Name('Metadata'), 'Subtype' => new Name('XML')]),
                $xmp->build(),
                compress: false,
            );
            $catalog->set('Metadata', $this->writer->add($metadata));
        }
        $intent = $this->pdfx->intent();
        if (null !== $intent) {
            $catalog->set('OutputIntents', [$this->writer->add($intent->dictionary)]);
        }
        $catalogRef = $this->writer->add($catalog);
        $infoRef    = $this->writer->add($this->infoDictionary());

        $this->writer->finish(Dictionary::of(['Root' => $catalogRef, 'Info' => $infoRef]));
        $this->closed = true;
    }

    public function currentPage(): ?Page
    {
        return $this->current;
    }

    /**
     * The ICCBased colour space DeviceRGB is interpreted in, when an RGB profile was given.
     *
     * @internal
     */
    public function defaultRgb(): ?Reference
    {
        $profile = $this->options->rgbProfile;
        if (null === $profile) {
            return null;
        }
        $stream = $this->objects->shared(
            "iccstream:{$profile->key()}",
            static fn(): Stream => new Stream(Dictionary::of(['N' => $profile->componentCount]), $profile->data),
        );

        return $this->objects->shared(
            "icc:{$profile->key()}",
            /** @return array{Name, Reference} */
            static fn(): array => [new Name('ICCBased'), $stream],
        );
    }

    /**
     * Registers an object to be written when the document closes.
     *
     * @internal
     */
    public function defer(DeferredInterface $deferred): void
    {
        $this->deferred[] = $deferred;
    }

    /**
     * Drains flowable content (a Textflow, a Table) across as many pages as it needs. The closure
     * makes each page (placing templates, headers and so on) and returns it; the content is then
     * fitted into that page's frames. Each entry of $frames is a page's frame, or a list of
     * frames (columns) filled in turn: when one is full the content moves to the next, and to a
     * new page after the last, or straight away at a page break. The first page uses the first
     * entry, the second page the second, and the last entry repeats, so page one can leave room
     * for a title block. Returns the number of pages used.
     *
     * @param Box|list<Box|list<Box>>      $frames
     * @param Closure(Document, int): Page $page   receives the document and the 1-based page index
     */
    public function flow(FlowableInterface $content, Box|array $frames, Closure $page): int
    {
        $this->assertOpen();
        $pages = self::framesPerPage($frames);
        $count = 0;
        do {
            ++$count;
            $boxes  = $pages[min($count, count($pages)) - 1] ?? [];
            $canvas = $page($this, $count)->canvas();
            $result = FlowResult::BoxFull;
            foreach ($boxes as $i => $box) {
                $result = $canvas->flow($content, $box);
                if (FlowResult::BoxEmpty === $result && 0 === $i) {
                    throw new LayoutException('The box is too small for any of the content; nothing was placed.');
                }
                if (FlowResult::BoxFull !== $result && FlowResult::BoxEmpty !== $result) {
                    break;
                }
            }
        } while (FlowResult::Done !== $result);

        return $count;
    }

    /** Fonts for this document: load by path, find by name, or group into families. */
    public function fonts(): FontRegistry
    {
        return $this->fonts ??= new FontRegistry($this);
    }

    /**
     * Loads an image file (JPEG or PNG natively; other formats through ext-imagick or ext-gd).
     * The same file is written once however many times it is loaded or placed.
     */
    public function image(string $path, ?ImageOptions $options = null): Image
    {
        $this->assertOpen();
        $data = file_get_contents($path);
        if (false === $data) {
            throw new IoException(sprintf('Cannot read image file "%s".', $path));
        }

        return $this->loadImage($data, $options ?? new ImageOptions(), $path);
    }

    /** Loads an image from memory. */
    public function imageFromData(string $data, ?ImageOptions $options = null): Image
    {
        $this->assertOpen();

        return $this->loadImage($data, $options ?? new ImageOptions(), name: null);
    }

    /**
     * Opens an existing PDF (or AI file saved as PDF-compatible) so its pages can be placed as
     * templates. The same file is imported once; shared resources are written once. Layers the
     * file switches off stay off; HiddenLayers::Remove removes their content instead, so viewers
     * that ignore layers in placed artwork (macOS Preview) do not show them either.
     */
    public function import(PdfFile|string $source, HiddenLayers $hiddenLayers = HiddenLayers::Keep): ImportedDocument
    {
        $this->assertOpen();
        $file = $source instanceof PdfFile ? $source : PdfFile::open($source, $this->options->limits);
        $key  = ($file->path ?? 'object#' . spl_object_id($file)) . '#' . $hiddenLayers->name;

        return $this->imports[$key] ??= new ImportedDocument($this, $file, $hiddenLayers);
    }

    public function info(): Info
    {
        return $this->info;
    }

    /** Named artwork that Textflow markup sets in the text with <img src="name">. */
    public function inlines(): InlineRegistry
    {
        return $this->inlines ??= new InlineRegistry();
    }

    /**
     * The document's PDF objects, for artwork of your own that writes its own resources.
     */
    public function objects(): Objects
    {
        return $this->objects;
    }

    /**
     * Runs a callback when close() is called, before the last page is finished: the page count is
     * final then, and templates placed on every page (a "Page X of Y" footer) can still be drawn
     * on, as can the last page. Callbacks run in the order they were added.
     *
     * @param Closure(Document): void $callback
     */
    public function onClose(Closure $callback): self
    {
        $this->assertOpen();
        $this->closing[] = $callback;

        return $this;
    }

    public function options(): DocumentOptions
    {
        return $this->options;
    }

    /** The document outline (bookmarks panel). */
    public function outline(): Outline
    {
        return $this->navigation()->outline;
    }

    public function pageCount(): int
    {
        return count($this->pageRefs) + (null === $this->current ? 0 : 1);
    }

    /**
     * What PDF/X asks of the document, checked as it is drawn.
     *
     * @internal
     */
    public function pdfx(): PdfXRules
    {
        return $this->pdfx;
    }

    /**
     * Declares the intended output condition (ICC profile), as PDF/X requires.
     *
     * @param string $identifier Registry name of the condition, e.g. "FOGRA39" or "sRGB IEC61966-2.1".
     */
    public function setOutputIntent(IccProfile $profile, string $identifier, ?string $info = null): self
    {
        $this->assertOpen();
        $profileRef = $this->objects->shared(
            "iccstream:{$profile->key()}",
            static fn(): Stream => new Stream(Dictionary::of(['N' => $profile->componentCount]), $profile->data),
        );
        $this->pdfx->setIntent(
            new OutputIntent(Dictionary::of([
                'Type'                      => new Name('OutputIntent'),
                'S'                         => new Name('GTS_PDFX'),
                'OutputConditionIdentifier' => $identifier,
                'Info'                      => $info ?? $identifier,
                'RegistryName'              => 'http://www.color.org',
                'DestOutputProfile'         => $profileRef,
            ]), $profile->componentCount),
        );

        return $this;
    }

    /** Named text styles for Textflow. */
    public function styles(): StyleRegistry
    {
        return $this->styles ??= new StyleRegistry();
    }

    /**
     * Converts an SVG so it can be placed as artwork. The native converter is used unless another
     * is given (RsvgConverter when rsvg-convert is installed). The same file converts once per
     * converter.
     */
    public function svg(SvgFile|string $source, ?SvgConverterInterface $converter = null): PlaceableInterface
    {
        $this->assertOpen();
        $file      = $source instanceof SvgFile ? $source : SvgFile::open($source, $this->options->limits);
        $converter ??= new NativeConverter();
        $key       = ($file->path ?? 'object#' . spl_object_id($file))
        . '|'
        . $converter::class
        . '#'
        . spl_object_id($converter);

        return $this->svgs[$key] ??= $converter->convert($file, $this);
    }

    /**
     * Creates reusable artwork of the given size. Draw on its canvas, then place it on pages.
     */
    public function template(Dimensions $size, ?Origin $origin = null, ?Box $bbox = null): Template
    {
        $this->assertOpen();

        return new Template(
            $this,
            $size,
            $origin ?? ($this->options->topDown ? Origin::TopLeft : Origin::BottomLeft),
            $bbox,
        );
    }

    /**
     * Creates a Textflow from plain text (the default) or from marked-up text (see TextFormat and
     * Markup::escape() for interpolating data into markup).
     *
     * @param array<string, Hyphenator> $hyphenators pattern hyphenators by language code
     */
    public function textflow(
        string $text,
        TextStyle|string|null $style = null,
        TextFormat $format = TextFormat::Plain,
        array $hyphenators = [],
    ): Textflow {
        $this->assertOpen();

        return TextFormat::Plain === $format
            ? Textflow::fromText($this, $text, $style, $hyphenators)
            : Textflow::fromMarkup($this, $text, $style, $hyphenators, $format);
    }

    /** @internal */
    public function writer(): Writer
    {
        return $this->writer;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new PdfException('The document has been closed.');
        }
    }

    private function finishCurrentPage(): void
    {
        if (null === $this->current) {
            return;
        }
        $this->pageRefs[] = $this->current->finish($this->pagesRef);
        $this->current    = null;
    }

    private function infoDictionary(): Dictionary
    {
        $date = TextString::date($this->createdAt);
        $dict = Dictionary::of([
            'Producer'     => TextString::encode($this->options->producer),
            'CreationDate' => $date,
            'ModDate'      => $date,
        ]);
        if (null !== $this->options->pdfx) {
            $dict->set('GTS_PDFXVersion', $this->options->pdfx->value);
            $dict->set('Trapped', new Name('False'));
        }
        foreach ([
            'Title'    => $this->info->title(),
            'Author'   => $this->info->author(),
            'Subject'  => $this->info->subject(),
            'Keywords' => $this->info->keywords(),
            'Creator'  => $this->info->creator(),
        ] as $key => $value) {
            if (null === $value) {
                continue;
            }

            $dict->set($key, TextString::encode($value));
        }

        return $dict;
    }

    private function loadImage(string $data, ImageOptions $options, ?string $name): Image
    {
        return $this->images[md5($data) . $options->key()] ??= new Image(
            $this,
            ImageReader::read($data, $this->options->limits, $options, $name)->withOptions($options),
        );
    }

    private function navigation(): Navigation
    {
        return $this->navigation ??= new Navigation($this->writer);
    }
}
