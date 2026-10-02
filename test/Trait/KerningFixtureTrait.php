<?php

declare(strict_types=1);

namespace Php2PdfTest\Trait;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Text\Flow\StyleResolver;
use Php2Pdf\Text\Hyphenation\Hyphenator;
use Php2Pdf\Text\Style\TextStyle;
use Php2PdfTest\TestAsset\PdfTools;

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
