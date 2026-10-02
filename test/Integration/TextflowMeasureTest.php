<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Textflow;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Measuring a flow at one width leaves it as it was: a word split to fit a narrow measure stays
 * whole for any later measure or fit.
 */
#[Group('integration')]
final class TextflowMeasureTest extends TestCase
{
    private Textflow $flow;

    #[Test]
    public function aNarrowMeasureLeavesTheWidestWordWhole(): void
    {
        $before = $this->flow->minContentWidth();
        $this->flow->measure(30.0);

        self::assertSame($before, $this->flow->minContentWidth());
    }

    #[Override]
    protected function setUp(): void
    {
        $document   = new Document(new MemoryOutput());
        $font       = $document->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $this->flow = $document->textflow(
            'A Donaudampfschifffahrtsgesellschaft captain',
            new TextStyle(
                font: $font,
                size: 10.0,
            ),
        );
    }
}
