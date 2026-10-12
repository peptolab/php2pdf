<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Closure;
use IntlChar;
use Override;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\ArabicFallback;
use Php2Pdf\Font\ArabicJoining;
use Php2Pdf\Font\Bidi\Bidi;
use Php2Pdf\Font\Normaliser;
use Php2Pdf\Font\OpenType\Gdef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function class_exists;

/**
 * Without ext-intl, text is set left to right as it comes: nothing is right to left, Arabic keeps
 * its nominal forms, text is not normalised, and marks are found by their Unicode category with
 * PCRE. CI runs these in a job without the extension; with it loaded they are skipped.
 */
#[Group('unit')]
#[Group('no-intl')]
final class WithoutIntlTest extends TestCase
{
    /**
     * An Arabic word (lam, alef, meem) as glyphs 1 to 3 asking for joined forms, which the
     * font has as glyphs 9.
     *
     * @return array<string, array{0: Closure(list<array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}>, list<int>, Closure(int): int): list<array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}>}>
     */
    public static function arabicProvider(): array
    {
        return [
            'presentation forms'    => [ArabicFallback::apply(...)],
            'the lam-alef ligature' => [ArabicFallback::lamAlef(...)],
        ];
    }

    /** @return array<string, array{0: list<int>, 1: list<int>}> code points, and the index each comes from */
    public static function normaliseProvider(): array
    {
        return [
            'e and a combining acute' => [[0x65, 0x0301], [0, 1]],
            'nothing'                 => [[], []],
        ];
    }

    /**
     * @param Closure(list<array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}>, list<int>, Closure(int): int): list<array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}> $substitute
     */
    #[Test]
    #[DataProvider('arabicProvider')]
    public function arabicKeepsItsNominalForms(Closure $substitute): void
    {
        $sequence = [[1, [0x0644], 0, 0, 'init'], [2, [0x0627], 0, 0, 'fina'], [3, [0x0645], 0, 0, 'isol']];

        self::assertSame($sequence, $substitute($sequence, [0x0644, 0x0627, 0x0645], static fn(int $_): int => 9));
    }

    #[Test]
    public function arabicLettersAreNotJoined(): void
    {
        self::assertSame(['', '', ''], ArabicJoining::forms([0x0644, 0x0627, 0x0645]));
    }

    #[Test]
    public function marksAreFoundByTheirUnicodeCategory(): void
    {
        $gdef = new Gdef(null, [0x61 => 8, 0x0301 => 7]);

        self::assertSame([Gdef::BASE, Gdef::MARK], [$gdef->glyphClass(8), $gdef->glyphClass(7)]);
    }

    #[Test]
    public function noCharacterIsRightToLeft(): void
    {
        self::assertFalse(Bidi::isRightToLeft(0x05D0));
    }

    /**
     * @param list<int> $codePoints
     * @param list<int> $sources
     */
    #[Test]
    #[DataProvider('normaliseProvider')]
    public function textIsLeftAsItComes(array $codePoints, array $sources): void
    {
        self::assertSame([$codePoints, $sources], Normaliser::normalise($codePoints, static fn(int $_): bool => true));
    }

    #[Test]
    public function theBidiAlgorithmAsksForTheExtension(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Right-to-left text needs the intl extension (ext-intl) for the bidi algorithm.');

        Bidi::paragraph([0x05D0]);
    }

    #[Override]
    protected function setUp(): void
    {
        if (! class_exists(IntlChar::class)) {
            return;
        }

        self::markTestSkipped('ext-intl is loaded: these fallbacks run only without it.');
    }
}
