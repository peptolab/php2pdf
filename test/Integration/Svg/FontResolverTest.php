<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Svg;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Svg\FontResolver;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** CSS font families resolve to the document's fonts, a style a family lacks to its regular face. */
#[Group('integration')]
final class FontResolverTest extends TestCase
{
    #[Test]
    public function aStyleTheFamilyLacksFallsBackToItsRegularFace(): void
    {
        $doc = new Document(new MemoryOutput());
        $doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'));
        $resolver = new FontResolver($doc, null);
        $regular  = $resolver->resolve(['DejaVu Sans'], bold: false, italic: false);

        self::assertNotNull($regular);
        self::assertSame($regular, $resolver->resolve(['DejaVu Sans'], bold: false, italic: true));
    }
}
