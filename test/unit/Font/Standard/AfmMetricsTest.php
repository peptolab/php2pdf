<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\Standard;

use Php2Pdf\Font\Standard\AfmMetrics;
use Php2Pdf\Font\StandardFont;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_diff;
use function array_keys;
use function array_unique;
use function array_values;
use function count;

#[Group('unit')]
final class AfmMetricsTest extends TestCase
{
    /** @return array<string, array{0: StandardFont}> */
    public static function fontProvider(): array
    {
        $fonts = [];
        foreach (StandardFont::cases() as $font) {
            $fonts[$font->value] = [$font];
        }

        return $fonts;
    }

    #[Test]
    #[DataProvider('fontProvider')]
    public function everyCodeTheFontMapsToHasAWidth(StandardFont $font): void
    {
        $metrics = AfmMetrics::of($font);
        $codes   = array_values(array_unique($metrics->codes));

        self::assertSame([], array_diff($codes, array_keys($metrics->widths)));
    }

    #[Test]
    #[DataProvider('fontProvider')]
    public function latinFontsCoverAllOfWinAnsiAndSymbolicFontsTheirOwnSets(StandardFont $font): void
    {
        $metrics = AfmMetrics::of($font);

        self::assertSame(
            [$font->isSymbolic() ? null : 'WinAnsiEncoding', true],
            [$metrics->encoding, $font->isSymbolic() ? count($metrics->codes) > 150 : 218 === count($metrics->codes)],
        );
    }

    #[Test]
    public function metricsAreLoadedOncePerFont(): void
    {
        self::assertSame(AfmMetrics::of(StandardFont::Courier), AfmMetrics::of(StandardFont::Courier));
    }
}
