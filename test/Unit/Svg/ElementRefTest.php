<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Svg\ElementRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ElementRefTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function hrefProvider(): array
    {
        return [
            'an id'               => ['#shape', 'shape'],
            'spaces around'       => [' #shape ', 'shape'],
            'no href'             => [null, null],
            'a hash alone'        => ['#', null],
            'a name without hash' => ['shape', null],
            'another file'        => ['other.svg#shape', null],
        ];
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function urlProvider(): array
    {
        return [
            'bare'          => ['url(#clip)', 'clip'],
            'single quotes' => ["url('#clip')", 'clip'],
            'double quotes' => ['url( "#clip" )', 'clip'],
            'spaces around' => ['  url(#clip)  ', 'clip'],
            'none'          => ['none', null],
            'no value'      => [null, null],
            'another file'  => ['url(other.svg#clip)', null],
        ];
    }

    #[Test]
    #[DataProvider('hrefProvider')]
    public function anHrefNamesAnElementOfTheSameDocument(?string $href, ?string $id): void
    {
        self::assertSame($id, ElementRef::fromHref($href));
    }

    #[Test]
    #[DataProvider('urlProvider')]
    public function aUrlNamesAnElementOfTheSameDocument(?string $value, ?string $id): void
    {
        self::assertSame($id, ElementRef::fromUrl($value));
    }

    #[Test]
    public function whatFollowsAUrlIsKept(): void
    {
        self::assertSame(['paint', 'red'], ElementRef::splitUrl('url(#paint) red'));
    }
}
