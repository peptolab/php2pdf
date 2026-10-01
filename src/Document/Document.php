<?php

declare(strict_types=1);

namespace PhpPdf\Document;

use Closure;
use DateTimeImmutable;
use PhpPdf\Canvas\FlowableInterface;
use PhpPdf\Canvas\PlaceableInterface;
use PhpPdf\Color\IccProfile;
use PhpPdf\Core\ClockInterface;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Core\Output\FileOutput;
use PhpPdf\Core\Output\OutputInterface;
use PhpPdf\Core\TextString;
use PhpPdf\Core\Writer;
use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Exception\IoException;
use PhpPdf\Exception\PdfException;
use PhpPdf\Font\FontRegistry;
use PhpPdf\Geometry\Box;
use PhpPdf\Geometry\Origin;
use PhpPdf\Image\Image;
use PhpPdf\Image\ImageReader;
use PhpPdf\Import\ImportedDocument;
use PhpPdf\Import\OptionalContent;
use PhpPdf\Import\PdfFile;
use PhpPdf\Svg\NativeConverter;
use PhpPdf\Svg\SvgConverterInterface;
use PhpPdf\Svg\SvgFile;
use PhpPdf\Template\Template;
use PhpPdf\Text\FlowResult;
use PhpPdf\Text\Hyphenation\Hyphenator;
use PhpPdf\Text\Style\StyleRegistry;
use PhpPdf\Text\Style\TextStyle;
use PhpPdf\Text\Textflow;
use PhpPdf\Text\TextFormat;

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

    /** @var list<Reference> */
    private array $pageRefs = [];

    /** @var list<DeferredInterface> */
    private array $deferred = [];

    /** @var array<string, Reference> */
    private array $shared = [];

    /** @var list<string> extra XMP descriptions */
    private array $xmpDescriptions = [];

    /** @var array<string, ImportedDocument> path => imported file */
    private array $imports = [];

    /** @var array<string, PlaceableInterface> svg key => converted artwork */
    private array $svgs = [];

    /** @var array<string, Image> content hash => image */
    private array $images = [];

    private ?FontRegistry  $fonts                  = null;
    private ?StyleRegistry $styles                 = null;
    private ?Dictionary    $outputIntent           = null;
    private ?Page          $current                = null;
    private bool           $closed                 = false;
    private ?Reference     $defaultRgb             = null;
    private ?Navigation    $navigation             = null;
    private ?int           $outputIntentComponents = null;

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
    public function addLinkAnnotation(Box $area, Destination|string $target): Reference
    {
        return $this->navigation()->addLink($area, $target);
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
     * Under PDF/X, checks that a device colour space suits the output intent: DeviceGray always,
     * DeviceRGB under an RGB intent or once an RGB profile (DefaultRGB) is set, DeviceCMYK under
     * a CMYK intent.
     *
     * @internal
     *
     * @param string $space DeviceGray, DeviceRGB or DeviceCMYK
     * @param string $what  what uses it, for the error message
     *
     * @throws PdfException when PDF/X does not allow the space here
     */
    public function assertDeviceColour(string $space, string $what = 'colours'): void
    {
        $pdfx = $this->options->pdfx;
        if (null === $pdfx) {
            return;
        }
        $this->assertOutputIntent();
        if ('DeviceRGB' === $space && null !== $this->options->rgbProfile) {
            return;
        }
        if ($this->intentAllows($space)) {
            return;
        }
        throw new PdfException(sprintf(
            'DeviceRGB' === $space
                ? '%s with %s output intent needs an RGB profile (DocumentOptions::$rgbProfile) before RGB %s can be used.'
                : '%s with %s output intent cannot use DeviceCMYK %s; use the output intent\'s colour space or an ICC-based colour.',
            $pdfx->value,
            $this->intentName(),
            $what,
        ));
    }

    /**
     * Under PDF/X, checks a device colour space an imported page uses. The RGB profile does not
     * reach imported artwork, whose own resources are kept, so DeviceRGB there needs an RGB
     * output intent.
     *
     * @internal
     *
     * @throws PdfException when PDF/X does not allow the space in the imported page
     */
    public function assertImportedColour(string $space, int $page): void
    {
        $pdfx = $this->options->pdfx;
        if (null === $pdfx) {
            return;
        }
        $this->assertOutputIntent();
        if ($this->intentAllows($space)) {
            return;
        }
        throw new PdfException(sprintf(
            '%s with %s output intent cannot place imported page %d: it draws in %s. Convert the artwork to the output intent\'s colour space first.',
            $pdfx->value,
            $this->intentName(),
            $page,
            $space,
        ));
    }

    /**
     * Under PDF/X, refuses colour until the output intent is set: the first fill, stroke, image
     * or gradient needs it, so the error names the call that did, not the close.
     *
     * @internal
     *
     * @throws PdfException when writing PDF/X without an output intent
     */
    public function assertOutputIntent(): void
    {
        $pdfx = $this->options->pdfx;
        if (null === $pdfx || null !== $this->outputIntent) {
            return;
        }
        throw new PdfException(
            "{$pdfx->value} needs an output intent before any colour, image or gradient is drawn: "
                . "call setOutputIntent() with the printing condition's ICC profile first.",
        );
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

        return match ($this->outputIntentComponents ?? 3) {
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
     * Finishes the current page and writes the page tree, catalog, metadata and trailer.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->assertOpen();
        $this->finishCurrentPage();
        if ([] === $this->pageRefs) {
            throw new PdfException('A document needs at least one page.');
        }
        $this->assertConformance();

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
        if (null !== $this->outputIntent) {
            $catalog->set('OutputIntents', [$this->writer->add($this->outputIntent)]);
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
        $stream = $this->shared(
            "iccstream:{$profile->key()}",
            static fn(): Stream => new Stream(Dictionary::of(['N' => $profile->componentCount]), $profile->data),
        );

        return $this->defaultRgb ??= $this->shared(
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
     * fitted into the box for that page. With a list of boxes the first page uses the first box,
     * the second page the second, and the last box repeats; so page one can leave room for a
     * title block. Returns the number of pages used.
     *
     * @param Box|list<Box>                   $boxes
     * @param Closure(Document, int): Page    $page  receives the document and the 1-based page index
     */
    public function flow(FlowableInterface $content, Box|array $boxes, Closure $page): int
    {
        $this->assertOpen();
        $list = $boxes instanceof Box ? [$boxes] : array_values($boxes);
        if ([] === $list) {
            throw new InvalidArgumentException('flow() needs at least one box.');
        }
        $pages = 0;
        do {
            ++$pages;
            $box = $list[min($pages, count($list)) - 1] ?? throw new InvalidArgumentException(
                'flow() needs at least one box.',
            );
            $target = $page($this, $pages);
            $result = $target->canvas()->flow($content, $box);
            if (FlowResult::BoxEmpty === $result) {
                throw new PdfException('The box is too small for any of the content; nothing was placed.');
            }
        } while (FlowResult::Done !== $result);

        return $pages;
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
    public function image(string $path): Image
    {
        $this->assertOpen();
        $data = file_get_contents($path);
        if (false === $data) {
            throw new IoException(sprintf('Cannot read image file "%s".', $path));
        }

        return $this->imageFromData($data);
    }

    /** Loads an image from memory. */
    public function imageFromData(string $data): Image
    {
        $this->assertOpen();

        return $this->images[md5($data)] ??= new Image($this, ImageReader::read($data, $this->options->limits));
    }

    /**
     * Opens an existing PDF (or AI file saved as PDF-compatible) so its pages can be placed as
     * templates. The same file is imported once; shared resources are written once.
     */
    public function import(PdfFile|string $source): ImportedDocument
    {
        $this->assertOpen();
        $file = $source instanceof PdfFile ? $source : PdfFile::open($source, $this->options->limits);
        $key  = $file->path ?? 'object#' . spl_object_id($file);

        return $this->imports[$key] ??= new ImportedDocument($this, $file);
    }

    public function info(): Info
    {
        return $this->info;
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
     * Declares the intended output condition (ICC profile), as PDF/X requires.
     *
     * @param string $identifier Registry name of the condition, e.g. "FOGRA39" or "sRGB IEC61966-2.1".
     */
    public function setOutputIntent(IccProfile $profile, string $identifier, ?string $info = null): self
    {
        $this->assertOpen();
        $profileRef = $this->shared(
            "iccstream:{$profile->key()}",
            static fn(): Stream => new Stream(Dictionary::of(['N' => $profile->componentCount]), $profile->data),
        );
        $this->outputIntentComponents = $profile->componentCount;
        $this->outputIntent           = Dictionary::of([
            'Type'                      => new Name('OutputIntent'),
            'S'                         => new Name('GTS_PDFX'),
            'OutputConditionIdentifier' => $identifier,
            'Info'                      => $info ?? $identifier,
            'RegistryName'              => 'http://www.color.org',
            'DestOutputProfile'         => $profileRef,
        ]);

        return $this;
    }

    /**
     * Writes an object once per document and returns its reference on later calls with the same key.
     *
     * @internal
     *
     * @param \Closure(): mixed $factory
     */
    public function shared(string $key, Closure $factory): Reference
    {
        return $this->shared[$key] ??= $this->writer->add($factory());
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

    /** Checks what a PDF/X level requires of the document as a whole and adds its declarations. */
    private function assertConformance(): void
    {
        $pdfx = $this->options->pdfx;
        if (null === $pdfx) {
            return;
        }
        if (null === $this->outputIntent) {
            throw new PdfException(
                "{$pdfx->value} needs an output intent: call setOutputIntent() with the printing condition's ICC profile.",
            );
        }
        if (null === $this->info->title()) {
            throw new PdfException("{$pdfx->value} needs a document title.");
        }
        if (! $this->options->xmp) {
            throw new PdfException("{$pdfx->value} needs XMP metadata; leave DocumentOptions::\$xmp enabled.");
        }
        $this->xmpDescriptions[] =
            '<rdf:Description rdf:about="" xmlns:pdfxid="http://www.npes.org/pdfx/ns/id/">'
            . "<pdfxid:GTS_PDFXVersion>{$pdfx->value}</pdfxid:GTS_PDFXVersion></rdf:Description>";
        $this->xmpDescriptions[] =
            '<rdf:Description rdf:about="" xmlns:pdf="http://ns.adobe.com/pdf/1.3/">'
            . '<pdf:Trapped>False</pdf:Trapped></rdf:Description>';
        $this->xmpDescriptions[] =
            '<rdf:Description rdf:about="" xmlns:xmpMM="http://ns.adobe.com/xap/1.0/mm/">'
            . '<xmpMM:VersionID>1</xmpMM:VersionID><xmpMM:RenditionClass>default</xmpMM:RenditionClass>'
            . '</rdf:Description>';
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

    /** Whether a device colour space matches the output intent's (DeviceGray always does). */
    private function intentAllows(string $space): bool
    {
        return match ($space) {
            'DeviceRGB'  => 3 === $this->outputIntentComponents,
            'DeviceCMYK' => 4 === $this->outputIntentComponents,
            default      => true,
        };
    }

    private function intentName(): string
    {
        return match ($this->outputIntentComponents) {
            1       => 'a grey',
            3       => 'an RGB',
            default => 'a CMYK',
        };
    }

    private function navigation(): Navigation
    {
        return $this->navigation ??= new Navigation($this->writer);
    }
}
