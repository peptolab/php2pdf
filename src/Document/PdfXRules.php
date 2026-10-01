<?php

declare(strict_types=1);

namespace PhpPdf\Document;

use PhpPdf\Exception\ConformanceException;

use function sprintf;

/**
 * What PDF/X asks of a document: an output intent before any colour, device colours its colour
 * space can reproduce, imported artwork in that space, a title and XMP metadata. Every check
 * passes when the document is not PDF/X.
 *
 * @internal
 */
final class PdfXRules
{
    private ?OutputIntent $intent = null;

    public function __construct(
        private readonly DocumentOptions $options,
    ) {}

    /**
     * Checks that a device colour space suits the output intent: DeviceGray always, DeviceRGB
     * under an RGB intent or once an RGB profile (DefaultRGB) is set, DeviceCMYK under a CMYK
     * intent.
     *
     * @param string $space DeviceGray, DeviceRGB or DeviceCMYK
     * @param string $what  what uses it, for the error message
     *
     * @throws ConformanceException when PDF/X does not allow the space here
     */
    public function assertDeviceColour(string $space, string $what = 'colours'): void
    {
        $pdfx = $this->options->pdfx;
        if (null === $pdfx) {
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
            $pdfx->value,
            $this->intentName(),
            $what,
        ));
    }

    /**
     * Checks a device colour space an imported page uses. The RGB profile does not reach imported
     * artwork, whose own resources are kept, so DeviceRGB there needs an RGB output intent.
     *
     * @throws ConformanceException when PDF/X does not allow the space in the imported page
     */
    public function assertImportedColour(string $space, int $page): void
    {
        $pdfx = $this->options->pdfx;
        if (null === $pdfx) {
            return;
        }
        $this->assertOutputIntent();
        if ($this->allows($space)) {
            return;
        }
        throw new ConformanceException(sprintf(
            '%s with %s output intent cannot place imported page %d: it draws in %s. Convert the artwork to the output intent\'s colour space first.',
            $pdfx->value,
            $this->intentName(),
            $page,
            $space,
        ));
    }

    /**
     * Refuses colour until the output intent is set: the first fill, stroke, image or gradient
     * needs it, so the error names the call that did, not the close.
     *
     * @throws ConformanceException when writing PDF/X without an output intent
     */
    public function assertOutputIntent(): void
    {
        $pdfx = $this->options->pdfx;
        if (null === $pdfx || null !== $this->intent) {
            return;
        }
        throw new ConformanceException(
            "{$pdfx->value} needs an output intent before any colour, image or gradient is drawn: "
                . "call setOutputIntent() with the printing condition's ICC profile first.",
        );
    }

    /**
     * Checks what PDF/X needs when the document closes, and returns the XMP descriptions that
     * declare it.
     *
     * @return list<string>
     *
     * @throws ConformanceException when the intent, title or XMP is missing
     */
    public function complete(?string $title): array
    {
        $pdfx = $this->options->pdfx;
        if (null === $pdfx) {
            return [];
        }
        if (null === $this->intent) {
            throw new ConformanceException(
                "{$pdfx->value} needs an output intent: call setOutputIntent() with the printing condition's ICC profile.",
            );
        }
        if (null === $title) {
            throw new ConformanceException("{$pdfx->value} needs a document title.");
        }
        if (! $this->options->xmp) {
            throw new ConformanceException("{$pdfx->value} needs XMP metadata; leave DocumentOptions::\$xmp enabled.");
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
}
