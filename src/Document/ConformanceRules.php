<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Override;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Objects;
use Php2Pdf\Exception\ConformanceException;

use function sprintf;

/**
 * What PDF/X and PDF/A ask of a document: an output intent before any colour, device colours its
 * colour space can reproduce, imported artwork in that space, XMP metadata declaring the
 * standards, and for PDF/X a title. The output intent is written once for each standard, sharing
 * its profile. Every check passes when the document conforms to neither.
 *
 * @internal
 */
final class ConformanceRules implements CatalogContributorInterface
{
    private ?OutputIntent $intent = null;

    public function __construct(
        private readonly DocumentOptions $options,
        private readonly Objects $objects,
    ) {}

    /**
     * Checks that a device colour space suits the output intent: DeviceGray always, DeviceRGB
     * under an RGB intent or once an RGB profile (DefaultRGB) is set, DeviceCMYK under a CMYK
     * intent.
     *
     * @param string $space DeviceGray, DeviceRGB or DeviceCMYK
     * @param string $what  what uses it, for the error message
     *
     * @throws ConformanceException when the standards do not allow the space here
     */
    public function assertDeviceColour(string $space, string $what = 'colours'): void
    {
        $standards = $this->options->conformance();
        if (null === $standards) {
            return;
        }
        $this->assertOutputIntent();
        if (('DeviceRGB' === $space && null !== $this->options->rgbProfile) || $this->allows($space)) {
            return;
        }
        throw new ConformanceException(sprintf(
            'DeviceRGB' === $space
                ? '%s with %s output intent needs an RGB profile (DocumentOptions::$rgbProfile) before RGB %s can be used.'
                : '%s with %s output intent cannot use DeviceCMYK %s; use the output intent\'s colour space or an ICC-based colour.',
            $standards,
            $this->intentName(),
            $what,
        ));
    }

    /**
     * Checks a device colour space an imported page uses. The RGB profile does not reach imported
     * artwork, whose own resources are kept, so DeviceRGB there needs an RGB output intent.
     *
     * @throws ConformanceException when the standards do not allow the space in the imported page
     */
    public function assertImportedColour(string $space, int $page): void
    {
        $standards = $this->options->conformance();
        if (null === $standards) {
            return;
        }
        $this->assertOutputIntent();
        if ($this->allows($space)) {
            return;
        }
        throw new ConformanceException(sprintf(
            '%s with %s output intent cannot place imported page %d: it draws in %s. Convert the artwork to the output intent\'s colour space first.',
            $standards,
            $this->intentName(),
            $page,
            $space,
        ));
    }

    /**
     * Refuses colour until the output intent is set: the first fill, stroke, image or gradient
     * needs it, so the error names the call that did, not the close.
     *
     * @throws ConformanceException when writing PDF/X or PDF/A without an output intent
     */
    public function assertOutputIntent(): void
    {
        $standards = $this->options->conformance();
        if (null === $standards || null !== $this->intent) {
            return;
        }
        throw new ConformanceException(
            "{$standards} needs an output intent before any colour, image or gradient is drawn: "
                . "call setOutputIntent() with the printing condition's ICC profile first.",
        );
    }

    /**
     * Checks what the standards need when the document closes, and adds the XMP that declares
     * them to the metadata, with the schemas of PDF/X's properties, which PDF/A needs declared.
     *
     * @throws ConformanceException when the intent, title or XMP is missing
     */
    public function complete(Metadata $metadata): void
    {
        $standards = $this->options->conformance();
        if (null === $standards) {
            return;
        }
        $title = $metadata->info->title();
        if (null === $this->intent) {
            throw new ConformanceException(
                "{$standards} needs an output intent: call setOutputIntent() with the printing condition's ICC profile.",
            );
        }
        $pdfx = $this->options->pdfx;
        if (null !== $pdfx && null === $title) {
            throw new ConformanceException("{$pdfx->value} needs a document title.");
        }
        if (! $this->options->xmp) {
            throw new ConformanceException("{$standards} needs XMP metadata; leave DocumentOptions::\$xmp enabled.");
        }

        foreach ([...$this->pdfxDescriptions(), ...$this->pdfaDescriptions()] as $description) {
            $metadata->addDescription($description);
        }
        if (null !== $pdfx) {
            $metadata->addSchema(new XmpSchema('PDF/X ID Schema', 'http://www.npes.org/pdfx/ns/id/', 'pdfxid', [
                ['GTS_PDFXVersion', 'Text', 'The version of PDF/X the document conforms to'],
            ]));
            $metadata->addSchema(new XmpSchema('Adobe PDF Schema', 'http://ns.adobe.com/pdf/1.3/', 'pdf', [
                ['Trapped', 'Text', 'Whether the document has been trapped'],
            ]));
        }
    }

    /** Adds the output intent to the catalog, once for each standard, once one is set. */
    #[Override]
    public function contribute(Dictionary $catalog, array $pageRefs): void
    {
        $intent = $this->intent;
        if (null === $intent) {
            return;
        }
        $intents = [];
        foreach ($this->subtypes() as $subtype) {
            $intents[] = $this->objects->add(Dictionary::of([
                'Type'                      => new Name('OutputIntent'),
                'S'                         => new Name($subtype),
                'OutputConditionIdentifier' => $intent->identifier,
                'Info'                      => $intent->info,
                'RegistryName'              => 'http://www.color.org',
                'DestOutputProfile'         => $intent->profile,
            ]));
        }
        $catalog->set('OutputIntents', $intents);
    }

    public function intent(): ?OutputIntent
    {
        return $this->intent;
    }

    public function setIntent(OutputIntent $intent): void
    {
        $this->intent = $intent;
    }

    private function allows(string $space): bool
    {
        return match ($space) {
            'DeviceRGB'  => 3 === $this->intent?->components,
            'DeviceCMYK' => 4 === $this->intent?->components,
            default      => true,
        };
    }

    private function intentName(): string
    {
        return match ($this->intent?->components) {
            1       => 'a grey',
            3       => 'an RGB',
            default => 'a CMYK',
        };
    }

    /** @return list<string> the XMP declaring the PDF/A part and its level */
    private function pdfaDescriptions(): array
    {
        $pdfa = $this->options->pdfa;
        if (null === $pdfa) {
            return [];
        }

        return [
            '<rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/">'
                . "<pdfaid:part>{$pdfa->part()}</pdfaid:part><pdfaid:conformance>B</pdfaid:conformance>"
                . '</rdf:Description>',
        ];
    }

    /** @return list<string> the XMP declaring the PDF/X version, its trapping and rendition */
    private function pdfxDescriptions(): array
    {
        $pdfx = $this->options->pdfx;
        if (null === $pdfx) {
            return [];
        }

        return [
            '<rdf:Description rdf:about="" xmlns:pdfxid="http://www.npes.org/pdfx/ns/id/">'
                . "<pdfxid:GTS_PDFXVersion>{$pdfx->value}</pdfxid:GTS_PDFXVersion></rdf:Description>",
            '<rdf:Description rdf:about="" xmlns:pdf="http://ns.adobe.com/pdf/1.3/">'
                . '<pdf:Trapped>False</pdf:Trapped></rdf:Description>',
            '<rdf:Description rdf:about="" xmlns:xmpMM="http://ns.adobe.com/xap/1.0/mm/">'
                . '<xmpMM:VersionID>1</xmpMM:VersionID><xmpMM:RenditionClass>default</xmpMM:RenditionClass>'
                . '</rdf:Description>',
        ];
    }

    /**
     * The output intent subtypes to write: GTS_PDFX for PDF/X, GTS_PDFA1 for PDF/A, and GTS_PDFX
     * for a document that conforms to neither but has an intent set.
     *
     * @return list<string>
     */
    private function subtypes(): array
    {
        $subtypes = [];
        if (null !== $this->options->pdfx || null === $this->options->pdfa) {
            $subtypes[] = 'GTS_PDFX';
        }
        if (null !== $this->options->pdfa) {
            $subtypes[] = 'GTS_PDFA1';
        }

        return $subtypes;
    }
}
