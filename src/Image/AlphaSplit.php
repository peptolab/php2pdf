<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use function max;
use function preg_replace;
use function str_repeat;

/**
 * Separates interleaved colour and alpha samples at their own depth: 16-bit samples stay 16-bit
 * in both the colour data and the soft mask, so no precision is lost.
 *
 * @internal
 */
final class AlphaSplit
{
    /**
     * @param int $channels samples per pixel, alpha last
     * @param int $bytes    bytes per sample (1 or 2)
     *
     * @return array{0: string, 1: string} colour samples and alpha, each $bytes per sample
     */
    public static function split(string $samples, int $channels, int $bytes): array
    {
        $sample      = '(' . str_repeat('.', max(1, $bytes)) . ')';
        $pixel       = '/' . str_repeat($sample, max(1, $channels)) . '/s';
        $colorGroups = '';
        for ($c = 1; $c < $channels; ++$c) {
            $colorGroups .= "\${$c}";
        }
        // Fixed-width groups with no backtracking cannot fail, and run far faster than a PHP loop.
        return [
            (string) preg_replace($pixel, $colorGroups, $samples),
            (string) preg_replace(
                $pixel,
                "\${$channels}",
                $samples,
            ),
        ];
    }
}
