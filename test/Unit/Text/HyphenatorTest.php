<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text;

use Php2Pdf\Text\Hyphenation\Hyphenator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function implode;

#[Group('unit')]
final class HyphenatorTest extends TestCase
{
    /** The patterns from Liang's thesis that hyphenate "hyphenation". */
    private const array PATTERNS = ['hy3ph', 'he2n', 'hena4', 'hen5at', '1na', 'n2at', '1tio', '2io', 'o2n'];

    #[Test]
    public function appliesLiangPatterns(): void
    {
        $h = new Hyphenator(self::PATTERNS);

        self::assertSame([2, 6], $h->hyphenate('hyphenation'));
        self::assertSame(['hy', 'phen', 'ation'], $h->syllables('hyphenation'));
        self::assertSame(['Hy', 'phen', 'ation'], $h->syllables('Hyphenation'), 'Case does not matter.');
    }

    #[Test]
    public function exceptionsWinOverPatterns(): void
    {
        $h = new Hyphenator(self::PATTERNS, ['hyphen-ation']);

        self::assertSame(['hyphen', 'ation'], $h->syllables('hyphenation'));
    }

    #[Test]
    public function honoursLeftAndRightMinimums(): void
    {
        $h = new Hyphenator(self::PATTERNS);

        self::assertSame([6], $h->hyphenate('hyphenation', 3, 2));
        self::assertSame([2], $h->hyphenate('hyphenation', 2, 6));
        self::assertSame([], $h->hyphenate('hy'));
    }

    #[Test]
    public function readsTexAndHunspellFormats(): void
    {
        $tex = "% comment\n\\patterns{\nhy3ph he2n hena4\nhen5at 1na n2at 1tio 2io o2n\n}\n\\hyphenation{\nta-ble\n}";
        $h   = Hyphenator::fromString($tex, 'en');
        self::assertSame(['hy', 'phen', 'ation'], $h->syllables('hyphenation'));
        self::assertSame(['ta', 'ble'], $h->syllables('table'));
        self::assertSame('en', $h->language);

        $dic = "UTF-8\nLEFTHYPHENMIN 2\nRIGHTHYPHENMIN 2\n" . implode("\n", self::PATTERNS) . "\n";
        self::assertSame(['hy', 'phen', 'ation'], Hyphenator::fromString($dic)->syllables('hyphenation'));
    }
}
