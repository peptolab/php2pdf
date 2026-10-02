<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Style;

use Php2Pdf\Font\FontInterface;
use Php2Pdf\Font\TextDirection;
use Php2Pdf\Text\Style\FormatKey;
use Php2Pdf\Text\Style\Leading;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FormatKeyTest extends TestCase
{
    /** @return array<string, array{0: list<mixed>, 1: list<mixed>}> */
    public static function differentProvider(): array
    {
        return [
            'floats a bit apart'        => [[0.1], [0.1 + 1.0e-16 + 1.0e-17]],
            'an int and a float'        => [[1], [1.0]],
            'a string and a number'     => [['1'], [1]],
            'null and false'            => [[null], [false]],
            'two enum cases'            => [
                [TextDirection::LeftToRight],
                [TextDirection::RightToLeft],
            ],
            'two leadings'              => [
                [Leading::pt(12.0)],
                [Leading::percent(12.0)],
            ],
            'list order'                => [[[1, 2]], [[2, 1]]],
            'strings that would run on' => [['ab', 'c'], ['a', 'bc']],
        ];
    }

    /**
     * @param list<mixed> $first
     * @param list<mixed> $second
     */
    #[Test]
    #[DataProvider('differentProvider')]
    public function differentValuesHaveDifferentKeys(array $first, array $second): void
    {
        self::assertNotSame(FormatKey::of($first), FormatKey::of($second));
    }

    #[Test]
    public function equalValuesShareAKey(): void
    {
        self::assertSame(
            FormatKey::of([12.0, Leading::pt(14.0), ['Sans'], TextDirection::Auto]),
            FormatKey::of([12.0, Leading::pt(14.0), ['Sans'], TextDirection::Auto]),
        );
    }

    #[Test]
    public function fontsAreToldApartByObject(): void
    {
        $font = self::createStub(FontInterface::class);

        self::assertSame(
            [true, false],
            [
                FormatKey::of([$font]) === FormatKey::of([$font]),
                FormatKey::of([$font]) === FormatKey::of([self::createStub(FontInterface::class)]),
            ],
        );
    }
}
