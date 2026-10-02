<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Font\ExternalShaperInterface;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2Pdf\Font\TextDirection;
use Php2Pdf\Font\TextOptions;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Runs in scripts the engine does not shape go to the document's complex shaper (#33). The stub
 * returns what hb-shape gives for Lao in DejaVu Sans: one glyph per character, the last two
 * characters (a consonant and its vowel sign) one cluster.
 */
#[Group('integration')]
final class ExternalShaperTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: TextDirection, 3: list<string>}> */
    private array $calls = [];

    #[Test]
    public function aComplexRunIsShapedByTheExternalShaper(): void
    {
        $glyphs = $this->shape("\u{0EAA}\u{0EB0}\u{0E9A}\u{0EB2}\u{0E8D}\u{0E94}\u{0EB5}", new TextOptions());

        self::assertSame(
            [
                [1594, 0x0EAA, []],
                [1599, 0x0EB0, []],
                [1583, 0x0E9A, []],
                [1601, 0x0EB2, []],
                [1577, 0x0E8D, []],
                [1578, 0x0E94, [0x0E94, 0x0EB5]],
                [1604, -1, []],
            ],
            array_map(static fn(Glyph $g): array => [$g->id, $g->codePoint, $g->components], $glyphs),
        );
    }

    #[Test]
    public function latinStaysWithTheEngine(): void
    {
        $this->shape('Latin text', new TextOptions());

        self::assertSame([], $this->calls);
    }

    #[Test]
    public function optionsBecomeFeaturesAndTheScriptIsPassed(): void
    {
        $this->shape("\u{0EAA}", new TextOptions(
            kerning: false,
            ligatures: false,
            features: ['ss01'],
        ));

        self::assertSame([['laoo', TextDirection::Auto, ['ss01', '-liga', '-clig', '-kern']]], array_map(
            static fn(array $call): array => [$call[1], $call[2], $call[3]],
            $this->calls,
        ));
    }

    #[Test]
    public function positionsBecomeKerningAndOffsets(): void
    {
        $last = $this->shape("\u{0E94}\u{0EB5}", new TextOptions())[1] ?? null;

        self::assertEqualsWithDelta(
            [0.0, 1.953, -$last?->advance],
            [$last?->xOffset, $last?->yOffset, $last?->kerning],
            0.001,
        );
    }

    /** @return list<Glyph> */
    private function shape(string $text, TextOptions $options): array
    {
        $shaper = new class($this->calls) implements ExternalShaperInterface {
            /** @param list<array{0: string, 1: string, 2: TextDirection, 3: list<string>}> $calls */
            public function __construct(
                private array &$calls,
            ) {}

            #[Override]
            public function shape(
                OpenTypeFont $font,
                string $text,
                string $script,
                TextDirection $direction,
                array $features,
            ): array {
                $this->calls[] = [$text, $script, $direction, $features];
                $lao           = [
                    [1594, 0,  1440, 0, 0, 0],
                    [1599, 3,  1295, 0, 0, 0],
                    [1583, 6,  1280, 0, 0, 0],
                    [1601, 9,  1104, 0, 0, 0],
                    [1577, 12, 1408, 0, 0, 0],
                    [1578, 15, 1371, 0, 0, 0],
                    [1604, 15, 0,    0, 0, 4],
                ];

                return (
                    "\u{0E94}\u{0EB5}" === $text
                        ? [
                            [1578, 0, 1371, 0, 0, 0],
                            [1604, 0, 0,    0, 0, 4],
                        ] : $lao
                );
            }
        };
        $doc = new Document(new MemoryOutput(), new DocumentOptions(complexShaper: $shaper));

        return $doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'))->shape($text, $options)->glyphs;
    }
}
