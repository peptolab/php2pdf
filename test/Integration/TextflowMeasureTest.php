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
 * whole for any later measure or fit. Text with words wider than the measure is measured all the
 * same.
 */
#[Group('integration')]
final class TextflowMeasureTest extends TestCase
{
    private Document $document;
    private Textflow $flow;
    private TextStyle $style;

    #[Test]
    public function addressesWiderThanATableCellAreMeasuredAcrossLines(): void
    {
        $flow = $this->document->textflow(
            "https://www.opentable.com.au/r/etta-dining-reservations-brunswick-east?restref=44399&lang=en-AU&ot_source=Restaurant%20website\n"
                . 'https://www.opentable.com.au/booking/experiences-availability?rid=44399&restref=44399'
                . '&experienceId=779691&utm_source=external&utm_medium=referral&utm_campaign=shared',
            $this->style->merge(new TextStyle(size: 9.0)),
        );

        self::assertGreaterThan(
            6 * 9.0,
            $flow->measure(198.43),
            "Two addresses some four times the cell's width take at least six lines.",
        );
    }

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
        $this->document = new Document(new MemoryOutput());
        $this->style    = new TextStyle(
            font: $this->document->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf')),
            size: 10.0,
        );
        $this->flow = $this->document->textflow('A Donaudampfschifffahrtsgesellschaft captain', $this->style);
    }
}
