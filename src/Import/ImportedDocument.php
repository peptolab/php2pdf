<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Php2Pdf\Color\ColourPolicyInterface;
use Php2Pdf\Core\Objects;
use Php2Pdf\Exception\ParseException;

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

    /**
     * @internal
     *
     * @throws ParseException
     */
    public function __construct(
        private readonly Objects $objects,
        private readonly ColourPolicyInterface $colour,
        public readonly PdfFile $file,
        public readonly HiddenLayers $hiddenLayers = HiddenLayers::Keep,
        public readonly ImportedAnnotations $annotations = ImportedAnnotations::None,
        /** The standards that need every font embedded (PDF/X, PDF/A), or null. */
        private readonly ?string $embeddingRequiredBy = null,
    ) {
        $this->hidden = HiddenLayers::Remove === $hiddenLayers ? HiddenContent::of($file->reader()) : null;
        $this->copier = new ObjectCopier($file->reader(), $objects, $this->hidden);
    }

    /**
     * Adds the layers the copied pages use to the document's optional content.
     *
     * @internal
     *
     * @throws ParseException
     */
    public function addOptionalContent(OptionalContent $layers): void
    {
        $layers->add($this->file->reader(), $this->copier, $this->hidden);
    }

    /**
     * A page by 1-based number, bounded by the given box (the crop box by default).
     *
     * @throws ParseException
     */
    public function page(int $number, PageBox $box = PageBox::Crop): ImportedPage
    {
        $key  = "{$number}:{$box->value}";
        $done = $this->pages[$key] ?? null;
        if (null !== $done) {
            return $done;
        }
        $page = $this->file->page($number);

        return $this->pages[$key] = new ImportedPage(
            $this->objects,
            $this->colour,
            $this->copier,
            $page,
            $box,
            $this->hidden,
            ImportedAnnotations::Flatten === $this->annotations
                ? new AnnotationFlattener($this->file->reader(), $page, $this->hidden)
                : null,
            $this->embeddingRequiredBy,
        );
    }

    /** @throws ParseException */
    public function pageCount(): int
    {
        return $this->file->pageCount();
    }
}
