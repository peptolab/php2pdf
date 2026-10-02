<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Style;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Text\Style\Justification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class JustificationTest extends TestCase
{
    /** @return array<string, array{float, float}> */
    public static function invalidProvider(): array
    {
        return [
            'a minimum of nothing' => [0.0, 133.0],
            'a minimum above 100'  => [101.0, 133.0],
            'a maximum below 100'  => [80.0, 99.0],
        ];
    }

    /** @return array<string, array{float, float, float, float, string}> */
    public static function invalidRangeProvider(): array
    {
        return [
            'letters closing up by a whole space' => [-100.0, 0.0, 100.0, 100.0, 'Letter spacing'],
            'a letter minimum above 0'            => [5.0, 10.0, 100.0, 100.0, 'Letter spacing'],
            'a letter maximum below 0'            => [-10.0, -5.0, 100.0, 100.0, 'Letter spacing'],
            'glyphs scaled to nothing'            => [0.0, 0.0, 0.0, 100.0, 'Glyph scaling'],
            'a glyph minimum above 100'           => [0.0, 0.0, 101.0, 105.0, 'Glyph scaling'],
            'a glyph maximum below 100'           => [0.0, 0.0, 90.0, 99.0, 'Glyph scaling'],
        ];
    }

    #[Test]
    public function aStyleWithoutJustificationHasInDesignsDefaults(): void
    {
        self::assertSame(
            [(new Justification())->shrink(), (new Justification())->stretch()],
            [Justification::shrinkOf(null), Justification::stretchOf(null)],
        );
    }

    #[Test]
    public function byDefaultLettersAndGlyphsDoNotChange(): void
    {
        $justification = new Justification();

        self::assertSame(
            [0.0, 0.0, 0.0, 0.0],
            [
                $justification->letterStretch(),
                $justification->letterShrink(),
                $justification->glyphStretch(),
                $justification->glyphShrink(),
            ],
        );
    }

    #[Test]
    public function letterAndGlyphLimitsAreFractions(): void
    {
        $justification = new Justification(
            minLetterSpacing: -5.0,
            maxLetterSpacing: 10.0,
            minGlyphScaling: 97.0,
            maxGlyphScaling: 104.0,
        );

        self::assertEqualsWithDelta(
            [0.1, 0.05, 0.04, 0.03],
            [
                $justification->letterStretch(),
                $justification->letterShrink(),
                $justification->glyphStretch(),
                $justification->glyphShrink(),
            ],
            1.0e-12,
        );
    }

    #[Test]
    #[DataProvider('invalidRangeProvider')]
    public function letterAndGlyphLimitsMustBracketTheUnchangedValue(
        float $minLetter,
        float $maxLetter,
        float $minGlyph,
        float $maxGlyph,
        string $setting,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("{$setting} limits must bracket");

        new Justification(100.0, 100.0, $minLetter, $maxLetter, $minGlyph, $maxGlyph);
    }

    #[Test]
    public function limitsAreFractionsOfTheSpace(): void
    {
        $justification = new Justification(70.0, 150.0);

        self::assertEqualsWithDelta([0.3, 0.5], [$justification->shrink(), $justification->stretch()], 1.0e-12);
    }

    #[Test]
    #[DataProvider('invalidProvider')]
    public function limitsMustBracketTheSpace(float $min, float $max): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Word spacing limits must bracket 100%');

        new Justification($min, $max);
    }
}
