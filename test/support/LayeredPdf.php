<?php

declare(strict_types=1);

namespace PhpPdfTest\Support;

use function sprintf;
use function strlen;

/**
 * A one-page PDF drawn in two layers, "Artwork" (a CMYK square at the bottom left) and "Guides"
 * (a black square at the top right), with a third layer, "Unused", that the page never refers to.
 * Objects 5, 6 and 7 are the Artwork, Guides and Unused groups.
 */
final class LayeredPdf
{
    /** The default configuration hides Guides. */
    public const string GUIDES_OFF = '<< /OFF [6 0 R] /Order [[(Layers) 5 0 R 6 0 R 7 0 R]] >>';

    /** @param string $config the source's default optional content configuration (/D) */
    public static function make(string $config = self::GUIDES_OFF): string
    {
        $content = '/OC /Art BDC 0 1 1 0 k 0 0 50 50 re f EMC /OC /Guides BDC 0 g 50 50 50 50 re f EMC';

        return HostileFiles::pdf([
            sprintf('<< /Type /Catalog /Pages 2 0 R /OCProperties << /OCGs [5 0 R 6 0 R 7 0 R] /D %s >> >>', $config),
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Contents 4 0 R '
                . '/Resources << /Properties << /Art 5 0 R /Guides 6 0 R >> >> >>',
            sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content),
            '<< /Type /OCG /Name (Artwork) >>',
            '<< /Type /OCG /Name (Guides) >>',
            '<< /Type /OCG /Name (Unused) >>',
        ]);
    }
}
