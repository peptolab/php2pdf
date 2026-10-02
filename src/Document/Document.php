<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Closure;
use Override;
use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Color\ColourPolicyInterface;
use Php2Pdf\Color\IccProfile;
use Php2Pdf\Core\ClockInterface;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Objects;
use Php2Pdf\Core\Output\FileOutput;
use Php2Pdf\Core\Output\OutputInterface;
use Php2Pdf\Core\Writer;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\LayoutException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\StateException;
use Php2Pdf\Flow\FlowableInterface;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Font\FontOptions;
use Php2Pdf\Font\FontRegistry;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Geometry\Origin;
use Php2Pdf\Image\Image;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Import\HiddenLayers;
use Php2Pdf\Import\ImportedDocument;
use Php2Pdf\Import\PdfFile;
use Php2Pdf\Navigation\Destination;
use Php2Pdf\Svg\SvgConverterInterface;
use Php2Pdf\Svg\SvgDocumentInterface;
use Php2Pdf\Svg\SvgFile;
use Php2Pdf\Table\CellStyle;
use Php2Pdf\Table\Column;
use Php2Pdf\Table\Table;
use Php2Pdf\Table\TableOptions;
use Php2Pdf\Template\Template;
use Php2Pdf\Text\Flow\TextflowBuilder;
use Php2Pdf\Text\Hyphenation\Hyphenator;
use Php2Pdf\Text\InlineRegistry;
use Php2Pdf\Text\Style\StyleRegistry;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\TextContext;
use Php2Pdf\Text\Textflow;
use Php2Pdf\Text\TextFormat;

use function array_values;
use function count;
use function is_string;
use function min;

/**
 * A PDF document being written. Pages are streamed to the output as soon as the next page starts,
 * so memory use stays flat for long documents; fonts and other deferred objects are written when
 * the document closes.
 *
 * @api
 */
final class Document implements SvgDocumentInterface
{
    private readonly Writer $writer;
    private readonly DocumentOptions $options;
    private readonly Objects $objects;
    private readonly PageTree $pages;
    private readonly PdfXRules $pdfx;
    private readonly DocumentColour $colour;
    private readonly Metadata $metadata;
    private readonly Assets $assets;
    private readonly TextContext $text;
    private ?Navigation $navigation = null;

    /** @var list<Closure(Document): void> */
    private array $closing = [];

    private bool $closed = false;

    /** @throws IoException */
    public function __construct(OutputInterface|string $output, ?DocumentOptions $options = null)
    {
        $this->options = $options ?? new DocumentOptions();
        $this->writer  = new Writer(
            is_string($output) ? new FileOutput($output) : $output,
            $this->options->compress,
            $this->options->compressionLevel,
        );
        $this->writer->writeHeader($this->options->version);
        $this->objects  = new Objects($this->writer);
        $this->pages    = new PageTree($this->objects);
        $this->pdfx     = new PdfXRules($this->options, $this->objects);
        $this->colour   = new DocumentColour($this->options, $this->pdfx, $this->objects);
        $this->metadata = new Metadata(new Info(), $this->options, $this->options->clock->now(), $this->objects);
        $this->assets   = new Assets($this->objects, $this->colour, $this->options);
        $this->text     = new TextContext(
            new FontRegistry($this->objects, new FontOptions(
                $this->options->glyphCheck,
                $this->options->scriptCheck,
                $this->options->complexShaper,
                $this->options->pdfx?->value,
            )),
            new StyleRegistry(),
            new InlineRegistry(),
        );
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
        $this->pages->finishCurrent();
        $dimensions = $size instanceof PageSize ? $size->dimensions() : $size;

        return $this->pages->open(new Page(
            $this,
            $dimensions,
            $topDown ?? $this->options->topDown,
            $this->pages->nextNumber(),
        ));
    }

    public function clock(): ClockInterface
    {
        return $this->options->clock;
    }

    /**
     * Runs the onClose() callbacks, then finishes the current page and writes the page tree,
     * catalog, metadata and trailer.
     *
     * @throws ParseException
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
        $this->pages->finishCurrent();
        if (0 === $this->pages->count()) {
            throw new StateException('A document needs at least one page.');
        }
        foreach ($this->pdfx->complete($this->metadata->info->title()) as $description) {
            $this->metadata->addDescription($description);
        }
        $this->objects->finalize();

        $catalog  = Dictionary::of(['Type' => new Name('Catalog'), 'Pages' => $this->pages->write()]);
        $pageRefs = $this->pages->references();
        foreach ([$this->navigation, $this->assets, $this->metadata, $this->pdfx] as $contributor) {
            $contributor?->contribute($catalog, $pageRefs);
        }
        $catalogRef = $this->objects->add($catalog);
        $infoRef    = $this->metadata->writeInfo();

        $this->writer->finish(Dictionary::of(['Root' => $catalogRef, 'Info' => $infoRef]));
        $this->closed = true;
    }

    /**
     * What the document asks of colour drawn into it, and its colour spaces.
     *
     * @internal
     */
    public function colour(): ColourPolicyInterface
    {
        return $this->colour;
    }

    public function currentPage(): ?Page
    {
        return $this->pages->current();
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
                $result = $content->fitInto($canvas, $box);
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
    #[Override]
    public function fonts(): FontRegistry
    {
        return $this->text->fonts;
    }

    /**
     * Loads an image file (JPEG or PNG natively; other formats through ext-imagick or ext-gd).
     * The same file is written once however many times it is loaded or placed.
     *
     * @throws IoException
     */
    public function image(string $path, ?ImageOptions $options = null): Image
    {
        $this->assertOpen();

        return $this->assets->image($path, $options ?? new ImageOptions());
    }

    /** Loads an image from memory. */
    #[Override]
    public function imageFromData(string $data, ?ImageOptions $options = null): Image
    {
        $this->assertOpen();

        return $this->assets->imageFromData($data, $options ?? new ImageOptions());
    }

    /**
     * Opens an existing PDF (or AI file saved as PDF-compatible) so its pages can be placed as
     * templates. The same file is imported once; shared resources are written once. Layers the
     * file switches off stay off; HiddenLayers::Remove removes their content instead, so viewers
     * that ignore layers in placed artwork (macOS Preview) do not show them either.
     *
     * @throws ParseException
     */
    #[Override]
    public function import(PdfFile|string $source, HiddenLayers $hiddenLayers = HiddenLayers::Keep): ImportedDocument
    {
        $this->assertOpen();

        return $this->assets->import($source, $hiddenLayers);
    }

    public function info(): Info
    {
        return $this->metadata->info;
    }

    /** Named artwork that Textflow markup sets in the text with <img src="name">. */
    public function inlines(): InlineRegistry
    {
        return $this->text->inlines;
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
        return $this->pages->count();
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
        return $this->text->styles;
    }

    /**
     * Converts an SVG so it can be placed as artwork. The native converter is used unless another
     * is given (RsvgConverter when rsvg-convert is installed). The same file converts once per
     * converter.
     *
     * @throws IoException
     * @throws ParseException
     */
    public function svg(SvgFile|string $source, ?SvgConverterInterface $converter = null): PlaceableInterface
    {
        $this->assertOpen();

        return $this->assets->svg($source, $converter, $this);
    }

    /**
     * Creates a table with the given columns, base cell style and base text style (a style or
     * the name of one defined in styles()). Add rows to it, then fit it with Page::fitTable() or
     * flow it across pages with flow().
     *
     * @param list<Column> $columns
     */
    public function table(
        array $columns,
        ?CellStyle $style = null,
        TextStyle|string|null $text = null,
        ?TableOptions $options = null,
    ): Table {
        $this->assertOpen();

        return new Table($this->text(), $columns, $style, $text, $options);
    }

    /**
     * Creates reusable artwork of the given size. Draw on its canvas, then place it on pages.
     */
    #[Override]
    public function template(Dimensions $size, ?Origin $origin = null, ?Box $bbox = null): Template
    {
        $this->assertOpen();

        return new Template(
            $this->objects,
            $this->colour,
            $size,
            $origin ?? ($this->options->topDown ? Origin::TopLeft : Origin::BottomLeft),
            $bbox,
        );
    }

    /**
     * The fonts, styles and inline objects text is set with.
     *
     * @internal
     */
    public function text(): TextContext
    {
        return $this->text;
    }

    /**
     * Creates a Textflow from plain text (the default) or from marked-up text (see TextFormat and
     * Markup::escape() for interpolating data into markup).
     *
     * @param array<string, Hyphenator> $hyphenators pattern hyphenators by language code
     *
     * @throws IoException
     * @throws ParseException
     */
    public function textflow(
        string $text,
        TextStyle|string|null $style = null,
        TextFormat $format = TextFormat::Plain,
        array $hyphenators = [],
    ): Textflow {
        $this->assertOpen();

        return TextFormat::Plain === $format
            ? Textflow::fromText($this->text(), $text, $style, $hyphenators)
            : Textflow::fromMarkup($this->text(), $text, $style, $hyphenators, $format);
    }

    /**
     * A builder for assembling a Textflow in code: text, styled runs, links, inline objects and
     * breaks, then build().
     *
     * @param array<string, Hyphenator> $hyphenators pattern hyphenators by language code
     *
     * @throws IoException
     * @throws ParseException
     */
    public function textflowBuilder(TextStyle|string|null $style = null, array $hyphenators = []): TextflowBuilder
    {
        $this->assertOpen();

        return Textflow::build($this->text(), $style, $hyphenators);
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new StateException('The document has been closed.');
        }
    }

    private function navigation(): Navigation
    {
        return $this->navigation ??= new Navigation($this->objects);
    }
}
