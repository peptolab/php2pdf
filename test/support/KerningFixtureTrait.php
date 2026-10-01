<?php

declare(strict_types=1);

namespace PhpPdfTest\Support;

use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Document;
use PhpPdf\Font\FontInterface;
use PhpPdf\Text\Flow\StyleResolver;
use PhpPdf\Text\Hyphenation\Hyphenator;
use PhpPdf\Text\Style\TextStyle;

/**
 * Source Serif 4 at 10 pt, whose kerning pairs the Itemiser and LineBreaker tests rely on:
 * T→space −19, space→T −20, e→T −50 but e→hyphen +10, n→T −60 but n→hyphen 0. "TrueType" and
 * "OpenType" hyphenate before "Type".
 */
trait KerningFixtureTrait
{
    private FontInterface $serif;
    private TextStyle $style;

    /** @var array<string, Hyphenator> */
    private array $hyphenators;

    private function setUpKerning(): void
    {
        $doc         = new Document(new MemoryOutput());
        $this->serif = $doc->fonts()->load(PdfTools::assetPath('fonts/SourceSerif4-Regular.ttf'));
        $this->style = (new StyleResolver($doc))->resolve(null, new TextStyle(
            font: $this->serif,
            size: 10.0,
            hyphenate: true,
            language: 'en',
        ));
        $this->hyphenators = ['en' => new Hyphenator([], ['true-type', 'open-type'])];
    }
}
