<?php

declare(strict_types=1);

namespace Php2Pdf\Font\Bidi;

use function array_key_exists;
use function count;

/**
 * Rule BD13 of the bidi algorithm: the isolating run sequences, each a list of character indices
 * in order: runs of one explicit level (passing over characters rule X9 removes), joined across
 * an isolate from its initiator to its matching PDI.
 *
 * @internal
 */
final class RunSequences
{
    /**
     * @param list<int>       $classes
     * @param list<int>       $levels            the explicit levels
     * @param array<int, int> $matchingPdi       isolate initiator => its PDI
     * @param array<int, int> $matchingInitiator PDI => its initiator
     *
     * @return list<list<int>>
     */
    public static function of(array $classes, array $levels, array $matchingPdi, array $matchingInitiator): array
    {
        $runs  = self::levelRuns($classes, $levels);
        $runOf = [];
        foreach ($runs as $r => $run) {
            foreach ($run as $i) {
                $runOf[$i] = $r;
            }
        }
        $sequences = [];
        foreach ($runs as $run) {
            $first = $run[0] ?? 0;
            if (array_key_exists($first, $matchingInitiator)) {
                continue;
            }
            $sequence = $run;
            $pdi      = self::continuation($sequence, $classes, $matchingPdi, $runOf);
            while (null !== $pdi) {
                foreach ($runs[$pdi] ?? [] as $i) {
                    $sequence[] = $i;
                }
                $pdi = self::continuation($sequence, $classes, $matchingPdi, $runOf);
            }
            $sequences[] = $sequence;
        }

        return $sequences;
    }

    /**
     * The run that continues a sequence ending in an isolate initiator: the one with its PDI.
     *
     * @param list<int>       $sequence
     * @param list<int>       $classes
     * @param array<int, int> $matchingPdi
     * @param array<int, int> $runOf
     */
    private static function continuation(array $sequence, array $classes, array $matchingPdi, array $runOf): ?int
    {
        $last = $sequence[count($sequence) - 1] ?? 0;
        if (! BidiClass::isIsolateInitiator($classes[$last] ?? -1)) {
            return null;
        }
        $pdi = $matchingPdi[$last] ?? null;

        return null === $pdi ? null : $runOf[$pdi] ?? null;
    }

    /**
     * Runs of characters at one explicit level.
     *
     * @param list<int> $classes
     * @param list<int> $levels
     *
     * @return list<list<int>>
     */
    private static function levelRuns(array $classes, array $levels): array
    {
        $runs    = [];
        $current = [];
        $level   = null;
        foreach ($classes as $i => $class) {
            if (BidiClass::isRemoved($class)) {
                continue;
            }
            $next = $levels[$i] ?? 0;
            if (null !== $level && $next !== $level) {
                $runs[]  = $current;
                $current = [];
            }
            $level     = $next;
            $current[] = $i;
        }
        if ([] !== $current) {
            $runs[] = $current;
        }

        return $runs;
    }
}
