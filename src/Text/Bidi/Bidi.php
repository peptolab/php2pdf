<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Bidi;

use IntlChar;
use Php2Pdf\Exception\PdfException;

use function class_exists;

/**
 * The bidi algorithm for text: each character's bidi class, paired bracket and mirrored form come
 * from ext-intl, which right-to-left text needs.
 *
 * @internal
 */
final class Bidi
{
    /** Whether a code point is right-to-left: R or AL. */
    public static function isRightToLeft(int $codePoint): bool
    {
        if (! class_exists(IntlChar::class)) {
            return false;
        }
        $class = (int) IntlChar::charDirection($codePoint);

        return BidiClass::R === $class || BidiClass::AL === $class;
    }

    /** The mirrored form of a character set right to left, such as ")" for "(". */
    public static function mirror(int $codePoint): int
    {
        self::assertAvailable();

        return (int) IntlChar::charMirror($codePoint);
    }

    /**
     * The bidi paragraph of a sequence of code points.
     *
     * @param list<int> $codePoints
     * @param int|null  $level      0 for left-to-right, 1 for right-to-left, null to take it from
     *                              the first strong character
     *
     * @throws PdfException without ext-intl
     */
    public static function paragraph(array $codePoints, ?int $level = null): BidiParagraph
    {
        self::assertAvailable();
        $classes = [];
        $types   = [];
        $pairs   = [];
        foreach ($codePoints as $codePoint) {
            $classes[] = (int) IntlChar::charDirection($codePoint);
            $type      = (int) IntlChar::getIntPropertyValue($codePoint, IntlChar::PROPERTY_BIDI_PAIRED_BRACKET_TYPE);
            $types[]   = $type;
            $closing   = 2 === $type ? $codePoint : (int) IntlChar::getBidiPairedBracket($codePoint);
            $pairs[]   = 0x232A === $closing ? 0x3009 : $closing;
        }

        return new BidiParagraph($classes, $types, $pairs, $level);
    }

    /** @throws PdfException without ext-intl */
    private static function assertAvailable(): void
    {
        if (! class_exists(IntlChar::class)) {
            throw new PdfException('Right-to-left text needs the intl extension (ext-intl) for the bidi algorithm.');
        }
    }
}
