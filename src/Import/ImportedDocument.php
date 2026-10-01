<?php

declare(strict_types=1);

namespace PhpPdf\Import;

use PhpPdf\Document\Document;

/**
 * An existing PDF bound to a document being written: each page can be placed as artwork, and
 * objects the pages share are written once.
 *
 * @api
 */
final class ImportedDocument
{
    private readonly ObjectCopier $copier;

    private readonly ?HiddenContent $hidden;

    /** @var array<string, ImportedPage> */
    private array $pages = [];

    /** @internal */
    public function __construct(
        private readonly Document $document,
        public readonly PdfFile $file,
        public readonly HiddenLayers $hiddenLayers = HiddenLayers::Keep,
    ) {
        $this->hidden = HiddenLayers::Remove === $hiddenLayers ? HiddenContent::of($file->reader()) : null;
        $this->copier = new ObjectCopier($file->reader(), $document->writer(), $this->hidden);
    }

    /**
     * Adds the layers the copied pages use to the document's optional content.
     *
     * @internal
     */
    public function addOptionalContent(OptionalContent $layers): void
    {
        $layers->add($this->file->reader(), $this->copier, $this->hidden);
    }

    /** A page by 1-based number, bounded by the given box (the crop box by default). */
    public function page(int $number, PageBox $box = PageBox::Crop): ImportedPage
    {
        return $this->pages["{$number}:{$box->value}"] ??= new ImportedPage(
            $this->document,
            $this->copier,
            $this->file->page($number),
            $box,
            $this->hidden,
        );
    }

    public function pageCount(): int
    {
        return $this->file->pageCount();
    }
}
