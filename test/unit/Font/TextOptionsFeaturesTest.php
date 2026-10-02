<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Font\TextOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The OpenType features shaping applies: HarfBuzz's defaults, the requested ones, and '-tag' (#33). */
#[Group('unit')]
final class TextOptionsFeaturesTest extends TestCase
{
    /** @return array<string, array{0: TextOptions, 1: list<string>}> */
    public static function optionsProvider(): array
    {
        return [
            'the defaults'                 => [
                new TextOptions(),
                ['ccmp', 'locl', 'rlig', 'calt', 'rclt', 'liga', 'clig'],
            ],
            'no ligatures'                 => [
                new TextOptions(ligatures: false),
                ['ccmp', 'locl', 'rlig', 'calt', 'rclt'],
            ],
            'tracking turns ligatures off' => [
                new TextOptions(tracking: 50.0),
                ['ccmp', 'locl', 'rlig', 'calt', 'rclt'],
            ],
            'a feature asked for'          => [
                new TextOptions(features: ['smcp', 'liga']),
                ['ccmp', 'locl', 'rlig', 'calt', 'rclt', 'liga', 'clig', 'smcp'],
            ],
            'a default turned off'         => [
                new TextOptions(features: ['-calt', 'onum']),
                ['ccmp', 'locl', 'rlig', 'rclt', 'liga', 'clig', 'onum'],
            ],
        ];
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('optionsProvider')]
    public function featuresFollowHarfBuzzDefaults(TextOptions $options, array $expected): void
    {
        self::assertSame($expected, $options->activeFeatures());
    }
}
