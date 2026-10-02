<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Font\FontInterface;
use Php2Pdf\Text\Style\CharFormat;
use Php2Pdf\Text\Style\TextStyle;

use function mb_ord;
use function mb_str_split;
use function spl_object_id;

/**
 * Text split where the font changes: each character set in the first of its style's font and
 * fallback fonts that has it.
 *
 * @internal
 */
final class FontRuns
{
    /**
     * Spans split where the font changes: each character goes to the first font in its style's
     * font and fallback list that has it, or to the font when none does. Spaces, tabs and line
     * breaks stay with the run they are in.
     *
     * @param list<array{0: string, 1: TextStyle}> $spans
     *
     * @return list<array{0: string, 1: TextStyle}>
     */
    public static function split(array $spans): array
    {
        $out = [];
        foreach ($spans as [$text, $style]) {
            $fallback = CharFormat::of($style)->fallback;
            if ([] === $fallback) {
                $out[] = [$text, $style];
                continue;
            }
            $font   = StyleResolver::fontOf($style);
            $styles = [spl_object_id($font) => $style];
            $run    = '';
            $using  = $font;
            foreach (mb_str_split($text, length: 1, encoding: 'UTF-8') as $char) {
                $choice = self::fontFor($char, $font, $fallback, $using);
                if ($choice !== $using && '' !== $run) {
                    $out[] = [$run, $styles[spl_object_id($using)] ??= $style->merge(new TextStyle(font: $using))];
                    $run   = '';
                }
                $using = $choice;
                $run   .= $char;
            }
            if ('' !== $run) {
                $out[] = [$run, $styles[spl_object_id($using)] ??= $style->merge(new TextStyle(font: $using))];
            }
        }

        return $out;
    }

    /**
     * The font that sets a character: the current one for spaces and breaks, otherwise the first
     * of the font and its fallbacks that has it, or the font itself when none does.
     *
     * @param list<FontInterface|string> $fallback resolved, so fonts only
     */
    private static function fontFor(
        string $char,
        FontInterface $font,
        array $fallback,
        FontInterface $current,
    ): FontInterface {
        $codePoint = mb_ord($char, encoding: 'UTF-8');
        if (false === $codePoint || $codePoint <= 0x20 || WordBreaks::SOFT_HYPHEN === $char) {
            return $current;
        }
        if ($font->hasGlyph($codePoint)) {
            return $font;
        }
        foreach ($fallback as $candidate) {
            if ($candidate instanceof FontInterface && $candidate->hasGlyph($codePoint)) {
                return $candidate;
            }
        }

        return $font;
    }
}
