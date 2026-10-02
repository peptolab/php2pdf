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

    #[Test]
    public function aStyleWithoutJustificationHasInDesignsDefaults(): void
    {
        self::assertSame(
            [(new Justification())->shrink(), (new Justification())->stretch()],
            [Justification::shrinkOf(null), Justification::stretchOf(null)],
        );
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
