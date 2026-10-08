<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font\OpenType;

use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2PdfTest\TestAsset\Font\SfntTables;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;

#[Group('integration')]
final class MetricsParserTest extends TestCase
{
    #[Test]
    public function aFontWithoutAPostTableTakesDefaultUnderlineAndSlant(): void
    {
        $data    = (string) file_get_contents(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $metrics = OpenTypeFont::fromData(SfntTables::replace($data, ['post' => null]))->metrics;

        self::assertSame(
            [-205, 102, 0.0, false],
            [$metrics->underlinePosition, $metrics->underlineThickness, $metrics->italicAngle, $metrics->isFixedPitch],
        );
    }
}
