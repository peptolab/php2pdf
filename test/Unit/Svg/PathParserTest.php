<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Svg\PathParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class PathParserTest extends TestCase
{
    /** @return array<string, array{0: ?string}> */
    public static function emptyDataProvider(): array
    {
        return [
            'no data'      => [null],
            'no commands'  => [''],
            'only garbage' => ['nothing here'],
        ];
    }

    #[Test]
    public function anArcToTheCurrentPointIsOmitted(): void
    {
        self::assertSame([['M', [5.0, 5.0]]], PathParser::parse('M5 5 A10 10 0 0 1 5 5')->commands);
    }

    #[Test]
    public function anArcWithAZeroRadiusIsAStraightLine(): void
    {
        self::assertSame(
            [['M', [0.0, 0.0]], ['L', [10.0, 0.0]]],
            PathParser::parse('M0 0 A0 5 0 0 1 10 0')->commands,
        );
    }

    #[Test]
    #[DataProvider('emptyDataProvider')]
    public function pathDataWithoutCommandsIsAnEmptyPath(?string $d): void
    {
        self::assertTrue(PathParser::parse($d)->isEmpty());
    }

    #[Test]
    public function radiiTooSmallForTheEndpointsAreScaledUp(): void
    {
        $bounds = PathParser::parse('M0 0 A1 1 0 0 1 10 0')->bounds();
        self::assertNotNull($bounds);

        self::assertEqualsWithDelta([10.0, 5.0], [$bounds->width, $bounds->height], 1.0e-6);
    }
}
