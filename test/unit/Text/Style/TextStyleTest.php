<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Style;

use Php2Pdf\Color\Gray;
use Php2Pdf\Text\Style\Leading;
use Php2Pdf\Text\Style\TabStop;
use Php2Pdf\Text\Style\TextStyle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class TextStyleTest extends TestCase
{
    #[Test]
    public function scalingKeepsRelativeLeadingRelative(): void
    {
        $style = new TextStyle(
            size: 10.0,
            leading: Leading::percent(130.0),
        );

        self::assertEquals(Leading::percent(130.0), $style->scaled(0.5)->leading);
    }

    #[Test]
    public function scalingLeavesUnsetMeasuresToInherit(): void
    {
        self::assertEquals(new TextStyle(font: 'Body'), (new TextStyle(font: 'Body'))->scaled(0.5));
    }

    #[Test]
    public function scalingMultipliesEveryMeasureCopyFittingShrinks(): void
    {
        $style = new TextStyle(
            font: 'Body',
            size: 10.0,
            leading: Leading::pt(12.0),
            color: Gray::black(),
            tracking: 20.0,
            firstLineIndent: 8.0,
            leftIndent: 20.0,
            rightIndent: 10.0,
            spaceBefore: 6.0,
            spaceAfter: 4.0,
            tabs: [TabStop::left(40.0), TabStop::decimal(100.0, ',', '.')],
            defaultTabWidth: 36.0,
        );

        self::assertEquals(
            new TextStyle(
                font: 'Body',
                size: 5.0,
                leading: Leading::pt(6.0),
                color: Gray::black(),
                tracking: 20.0,
                firstLineIndent: 4.0,
                leftIndent: 10.0,
                rightIndent: 5.0,
                spaceBefore: 3.0,
                spaceAfter: 2.0,
                tabs: [TabStop::left(20.0), TabStop::decimal(50.0, ',', '.')],
                defaultTabWidth: 18.0,
            ),
            $style->scaled(0.5),
        );
    }
}
