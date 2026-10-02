<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use DateTimeImmutable;
use Override;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Objects;
use Php2Pdf\Core\TextString;

/**
 * The document's metadata: the Info dictionary and the XMP packet, with the descriptions
 * conformance levels add to it.
 *
 * @internal
 */
final class Metadata implements CatalogContributorInterface
{
    /** @var list<string> RDF descriptions added to the XMP packet */
    private array $descriptions = [];

    public function __construct(
        public readonly Info $info,
        private readonly DocumentOptions $options,
        private readonly DateTimeImmutable $createdAt,
        private readonly Objects $objects,
    ) {}

    /** Adds an RDF description to the XMP packet, for a conformance declaration. */
    public function addDescription(string $rdf): void
    {
        $this->descriptions[] = $rdf;
    }

    /** Adds the XMP packet to the catalog, when the options ask for one. */
    #[Override]
    public function contribute(Dictionary $catalog, array $pageRefs): void
    {
        if (! $this->options->xmp) {
            return;
        }
        $xmp = new Xmp($this->info, $this->options->producer, $this->createdAt);
        foreach ($this->descriptions as $description) {
            $xmp->addDescription($description);
        }
        $catalog->set('Metadata', $this->objects->add(new Stream(
            Dictionary::of(['Type' => new Name('Metadata'), 'Subtype' => new Name('XML')]),
            $xmp->build(),
            compress: false,
        )));
    }

    /** Writes the Info dictionary, and returns its reference for the trailer. */
    public function writeInfo(): Reference
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

        return $this->objects->add($dict);
    }
}
