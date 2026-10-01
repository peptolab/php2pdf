<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Document;

use PhpPdf\Core\FixedClock;
use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Destination;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Exception\PdfException;
use PhpPdf\Geometry\Box;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function substr_count;

final class NavigationTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;

    #[Test]
    public function rejectsDestinationsToMissingPages(): void
    {
        $this->doc->addPage(PageSize::A4)->addLink(new Box(0.0, 0.0, 10.0, 10.0), Destination::page(9));
        $this->expectException(PdfException::class);
        $this->doc->close();
    }

    #[Test]
    public function rejectsUndefinedNamedDestinations(): void
    {
        $this->doc->addPage(PageSize::A4);
        $this->doc->outline()->add('x', Destination::named('nowhere'));
        $this->expectException(PdfException::class);
        $this->doc->close();
    }

    #[Test]
    public function writesLinkAnnotationsForUrlsAndDestinations(): void
    {
        $first = $this->doc->addPage(PageSize::A4);
        $first->addLink(new Box(10.0, 20.0, 100.0, 30.0), 'https://example.com/');
        $first->addLink(new Box(10.0, 60.0, 100.0, 30.0), Destination::page(2));
        $first->addLink(new Box(10.0, 100.0, 100.0, 30.0), Destination::named('chapter'));
        $second = $this->doc->addPage(PageSize::A4, topDown: true);
        $second->addLink(new Box(0.0, 0.0, 50.0, 50.0), Destination::at(1, 0.0, 800.0, 1.5));
        $this->doc->addDestination('chapter', Destination::top(2, 700.0));
        $this->doc->close();
        $pdf = $this->out->contents();

        self::assertSame(4, substr_count($pdf, needle: '/Subtype/Link'));
        self::assertStringContainsString('/A<</S/URI/URI(https://example.com/)>>', $pdf);
        self::assertStringContainsString('/Dest(chapter)', $pdf);
        self::assertSame(1, preg_match('#/Dests \d+ 0 R#', $pdf));
        self::assertSame(1, preg_match('#/chapter\[\d+ 0 R /FitH 700\]#', $pdf));
        self::assertSame(1, preg_match('#/Dest\[\d+ 0 R /XYZ 0 800 1\.5\]#', $pdf));
        // The top-down page's link area is flipped into PDF coordinates: y from 791.89 up.
        self::assertSame(1, preg_match('#/Rect\[0 791\.8898 50 841\.8898\]#', $pdf));
        self::assertStringContainsString('/Annots[', $pdf);
    }

    #[Test]
    public function writesTheOutlineTree(): void
    {
        $this->doc->addPage(PageSize::A4);
        $this->doc->addPage(PageSize::A4);
        $outline = $this->doc->outline();
        $one     = $outline->add('One', 1);
        $one->add('One A', Destination::top(1, 500.0));
        $one->add('One B', 2, open: false)->add('Deep', 2);
        $outline->add('Two', 2);
        $this->doc->close();
        $pdf = $this->out->contents();

        self::assertStringContainsString('/Type/Outlines', $pdf);
        self::assertStringContainsString('/PageMode/UseOutlines', $pdf);
        self::assertSame(1, preg_match('#/Type/Outlines/First \d+ 0 R/Last \d+ 0 R/Count 5#', $pdf));
        self::assertStringContainsString('/Title(One)', $pdf);
        self::assertStringContainsString('/Title(Deep)', $pdf);
        self::assertSame(
            1,
            preg_match('#/Title\(One B\)[^>]*/Count -1#', $pdf),
            'A closed entry has a negative count.',
        );
        self::assertSame(1, preg_match('#/Title\(One\)[^>]*/Count 3#', $pdf));
    }

    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
        ));
    }
}
