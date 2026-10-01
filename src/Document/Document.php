<?php

declare(strict_types=1);

namespace PhpPdf\Document;

use PhpPdf\Core\Clock;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Core\Output\FileOutput;
use PhpPdf\Core\Output\Output;
use PhpPdf\Core\TextString;
use PhpPdf\Core\Writer;
use PhpPdf\Exception\PdfException;

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
    private readonly \DateTimeImmutable $createdAt;

    /** @var list<Reference> */
    private array $pageRefs = [];

    /** @var list<Deferred> */
    private array $deferred = [];

    /** @var array<string, Reference> */
    private array $shared = [];

    /** @var list<string> extra XMP descriptions */
    private array $xmpDescriptions = [];

    private Dictionary|null $outputIntent = null;
    private Page|null $current = null;
    private bool $closed = false;

    public function __construct(Output|string $output, DocumentOptions|null $options = null)
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
     * Starts a new page. The previous page, if any, is finished and written out.
     */
    public function addPage(PageSize|Dimensions $size = PageSize::A4, bool|null $topDown = null): Page
    {
        $this->assertOpen();
        $this->finishCurrentPage();

        $dimensions    = $size instanceof PageSize ? $size->dimensions() : $size;
        $this->current = new Page($this, $dimensions, $topDown ?? $this->options->topDown, count($this->pageRefs) + 1);

        return $this->current;
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

        foreach ($this->deferred as $deferred) {
            $deferred->finalize($this);
        }

        $this->writer->write($this->pagesRef, Dictionary::of([
            'Type' => new Name('Pages'),
            'Kids' => $this->pageRefs,
            'Count' => count($this->pageRefs),
        ]));

        $catalog = Dictionary::of(['Type' => new Name('Catalog'), 'Pages' => $this->pagesRef]);
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

    public function clock(): Clock
    {
        return $this->options->clock;
    }

    public function currentPage(): Page|null
    {
        return $this->current;
    }

    /**
     * Registers an object to be written when the document closes.
     *
     * @internal
     */
    public function defer(Deferred $deferred): void
    {
        $this->deferred[] = $deferred;
    }

    public function info(): Info
    {
        return $this->info;
    }

    public function options(): DocumentOptions
    {
        return $this->options;
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
    public function setOutputIntent(\PhpPdf\Color\IccProfile $profile, string $identifier, string|null $info = null): self
    {
        $this->assertOpen();
        $profileRef = $this->shared(
            'iccstream:' . $profile->key(),
            static fn(): Stream => new Stream(Dictionary::of(['N' => $profile->componentCount]), $profile->data),
        );
        $this->outputIntent = Dictionary::of([
            'Type' => new Name('OutputIntent'),
            'S' => new Name('GTS_PDFX'),
            'OutputConditionIdentifier' => $identifier,
            'Info' => $info ?? $identifier,
            'RegistryName' => 'http://www.color.org',
            'DestOutputProfile' => $profileRef,
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
    public function shared(string $key, \Closure $factory): Reference
    {
        return $this->shared[$key] ??= $this->writer->add($factory());
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
            'Producer' => TextString::encode($this->options->producer),
            'CreationDate' => $date,
            'ModDate' => $date,
        ]);
        foreach (
            [
                'Title' => $this->info->title(),
                'Author' => $this->info->author(),
                'Subject' => $this->info->subject(),
                'Keywords' => $this->info->keywords(),
                'Creator' => $this->info->creator(),
            ] as $key => $value
        ) {
            if (null !== $value) {
                $dict->set($key, TextString::encode($value));
            }
        }

        return $dict;
    }
}
