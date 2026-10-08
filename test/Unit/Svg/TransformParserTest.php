<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Svg\TransformParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class TransformParserTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function identityProvider(): array
    {
        return [
            'no transform functions'   => ['none'],
            'a matrix of five numbers' => ['matrix(1 0 0 1 5)'],
        ];
    }

    #[Test]
    public function aSkewYShearsYByX(): void
    {
        self::assertEqualsWithDelta([1.0, 1.0], TransformParser::parse('skewY(45)')->apply(1.0, 0.0), 1.0e-9);
    }

    #[Test]
    #[DataProvider('identityProvider')]
    public function aValueWithoutAUsableTransformIsTheIdentity(string $value): void
    {
        self::assertTrue(TransformParser::parse($value)->isIdentity());
    }
}
