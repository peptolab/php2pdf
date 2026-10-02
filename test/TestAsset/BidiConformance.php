<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset;

use Php2Pdf\Text\Bidi\Bidi;
use Php2Pdf\Text\Bidi\BidiParagraph;

use function array_map;
use function count;
use function explode;
use function file;
use function hexdec;
use function implode;
use function str_starts_with;
use function substr;
use function trim;

use const FILE_IGNORE_NEW_LINES;

/**
 * Reads the Unicode bidi conformance files (BidiTest.txt by class, BidiCharacterTest.txt by
 * character) and gives each case's input with the levels and order it expects.
 */
final class BidiConformance
{
    private const array CLASSES = [
        'L'   => 0,
        'R'   => 1,
        'EN'  => 2,
        'ES'  => 3,
        'ET'  => 4,
        'AN'  => 5,
        'CS'  => 6,
        'B'   => 7,
        'S'   => 8,
        'WS'  => 9,
        'ON'  => 10,
        'LRE' => 11,
        'LRO' => 12,
        'AL'  => 13,
        'RLE' => 14,
        'RLO' => 15,
        'PDF' => 16,
        'NSM' => 17,
        'BN'  => 18,
        'FSI' => 19,
        'LRI' => 20,
        'RLI' => 21,
        'PDI' => 22,
    ];

    /**
     * Cases by character: the code points, the paragraph level (null for auto), and the expected
     * levels and order.
     *
     * @return list<array{0: list<int>, 1: int|null, 2: string, 3: string, 4: string}>
     */
    public static function characterCases(string $path): array
    {
        $cases = [];
        foreach (self::lines($path) as $line) {
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            [$hex, $direction, , $levels, $order] = array_map(trim(...), explode(';', $line));
            $cases[] = [
                array_map(static fn(string $h): int => (int) hexdec($h), explode(' ', $hex)),
                2 === (int) $direction ? null : (int) $direction,
                $levels,
                $order,
                $hex,
            ];
        }

        return $cases;
    }

    /** @param list<int> $codePoints */
    public static function characterResult(array $codePoints, ?int $level): array
    {
        return self::result(Bidi::paragraph($codePoints, $level), count($codePoints));
    }

    /**
     * Cases by class: the classes, the paragraph level (null for auto), and the expected levels
     * and order as the file writes them.
     *
     * @return list<array{0: list<int>, 1: int|null, 2: string, 3: string, 4: string}>
     */
    public static function classCases(string $path): array
    {
        $cases  = [];
        $levels = '';
        $order  = '';
        foreach (self::lines($path) as $line) {
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            if (str_starts_with($line, '@Levels:')) {
                $levels = trim(substr($line, offset: 8));
                continue;
            }
            if (str_starts_with($line, '@Reorder:')) {
                $order = trim(substr($line, offset: 9));
                continue;
            }
            [$input, $bits] = array_map(trim(...), explode(';', $line));
            $classes = array_map(static fn(string $c): int => self::CLASSES[$c] ?? 0, explode(' ', $input));
            foreach ([1 => null, 2 => 0, 4 => 1] as $bit => $level) {
                if (0 === ((int) $bits & $bit)) {
                    continue;
                }

                $cases[] = [$classes, $level, $levels, $order, $input];
            }
        }

        return $cases;
    }

    /**
     * The levels ('x' for characters rule X9 removes) and the visual order (without them) of a
     * one-line paragraph, as the files write them.
     *
     * @return array{0: string, 1: string}
     */
    public static function result(BidiParagraph $bidi, int $count): array
    {
        $line   = $bidi->lineLevels(0, $count);
        $levels = [];
        for ($i = 0; $i < $count; ++$i) {
            $levels[] = $bidi->isRemoved($i) ? 'x' : (string) ($line[$i] ?? 0);
        }
        $order = [];
        foreach ($bidi->visualOrder(0, $count) as $i) {
            if ($bidi->isRemoved($i)) {
                continue;
            }

            $order[] = (string) $i;
        }

        return [implode(' ', $levels), implode(' ', $order)];
    }

    /** @return list<string> */
    private static function lines(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        return false === $lines ? [] : $lines;
    }
}
