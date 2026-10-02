<?php

declare(strict_types=1);

/**
 * Runs the Unicode bidi conformance tests against the bidi algorithm:
 *
 *     php tools/bidi-conformance.php BidiTest.txt BidiCharacterTest.txt
 *
 * The files come from https://www.unicode.org/Public/<version>/ucd/ for the Unicode version ICU
 * implements (IntlChar::getUnicodeVersion()). Prints the pass and fail counts and the first
 * failures. test/asset/bidi holds a sample of each that the unit tests run.
 */

use Php2Pdf\Font\Bidi\BidiParagraph;
use Php2PdfTest\Support\BidiConformance;

require __DIR__ . '/../vendor/autoload.php';

$failures = [];
foreach ([1 => 'BidiTest', 2 => 'BidiCharacterTest'] as $argument => $name) {
    $path = $argv[$argument] ?? null;
    if (null === $path) {
        continue;
    }
    $passed = 0;
    $failed = 0;
    $cases  = 1 === $argument ? BidiConformance::classCases($path) : BidiConformance::characterCases($path);
    foreach ($cases as [$input, $level, $levels, $order, $label]) {
        $result = 1 === $argument
            ? BidiConformance::result(new BidiParagraph($input, level: $level), count($input))
            : BidiConformance::characterResult($input, $level);
        if ($result === [$levels, $order]) {
            ++$passed;
            continue;
        }
        ++$failed;
        if (count($failures) < 20) {
            $failures[] = "{$name} {$label} (level " . ($level ?? 'auto') . "): got {$result[0]} / {$result[1]}, expected {$levels} / {$order}";
        }
    }
    echo "{$name}: {$passed} passed, {$failed} failed\n";
}
foreach ($failures as $failure) {
    echo $failure, "\n";
}
