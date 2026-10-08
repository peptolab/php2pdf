<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Document;

use Php2Pdf\Document\PageSize;
use Php2Pdf\Geometry\Dimensions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class PageSizeTest extends TestCase
{
    /** @return array<string, array{0: PageSize, 1: Dimensions}> */
    public static function usSizeProvider(): array
    {
        return [
            'legal'   => [PageSize::Legal, Dimensions::in(8.5, 14.0)],
            'tabloid' => [PageSize::Tabloid, Dimensions::in(11.0, 17.0)],
        ];
    }

    #[Test]
    public function portraitIsTallerThanItIsWide(): void
    {
        self::assertEquals(Dimensions::in(8.5, 11.0), PageSize::Letter->portrait());
    }

    #[Test]
    #[DataProvider('usSizeProvider')]
    public function usSizesAreDefinedInInches(PageSize $size, Dimensions $expected): void
    {
        self::assertEquals($expected, $size->dimensions());
    }
}
