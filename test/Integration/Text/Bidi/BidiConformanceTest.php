<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text\Bidi;

use Php2Pdf\Font\Bidi\BidiParagraph;
use Php2PdfTest\TestAsset\BidiConformance;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * The bidi algorithm against samples of the Unicode 17 conformance files (#33); the whole files
 * pass with tools/bidi-conformance.php. Each assertion compares all cases, naming the failures.
 */
#[Group('integration')]
final class BidiConformanceTest extends TestCase
{
    #[Test]
    public function charactersResolveAsUnicodeSays(): void
    {
        $failures = [];
        foreach (BidiConformance::characterCases(PdfTools::assetPath('bidi/BidiCharacterTest-sample.txt')) as [
            $codePoints,
            $level,
            $levels,
            $order,
            $label,
        ]) {
            $result = BidiConformance::characterResult($codePoints, $level);
            if ([$levels, $order] !== $result) {
                $failures[] = "{$label} @" . ($level ?? 'auto') . ": {$result[0]} / {$result[1]}";
            }
        }

        self::assertSame([], $failures);
    }

    #[Test]
    public function classSequencesResolveAsUnicodeSays(): void
    {
        $failures = [];
        foreach (BidiConformance::classCases(PdfTools::assetPath('bidi/BidiTest-sample.txt')) as [
            $classes,
            $level,
            $levels,
            $order,
            $label,
        ]) {
            $result = BidiConformance::result(new BidiParagraph($classes, level: $level), count($classes));
            if ([$levels, $order] !== $result) {
                $failures[] = "{$label} @" . ($level ?? 'auto') . ": {$result[0]} / {$result[1]}";
            }
        }

        self::assertSame([], $failures);
    }
}
