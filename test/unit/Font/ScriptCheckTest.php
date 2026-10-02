<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\ComplexScripts;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Font\ScriptCheck;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Text in scripts the font engine cannot shape yet is refused or reported (#33). */
#[Group('unit')]
final class ScriptCheckTest extends TestCase
{
    /** @return array<string, array{0: string, 1: array{0: int, 1: string}}> */
    public static function complexProvider(): array
    {
        return [
            'Hebrew'                   => ["Shalom \u{05E9}\u{05DC}\u{05D5}\u{05DD}", [0x05E9, 'Hebrew']],
            'Arabic'                   => ["\u{0633}\u{0644}\u{0627}\u{0645}", [0x0633, 'Arabic']],
            'Arabic presentation form' => ["\u{FEF5}", [0xFEF5, 'Arabic']],
            'Devanagari'               => ["\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}", [0x0928, 'Devanagari']],
            'Thai'                     => ["\u{0E2A}\u{0E27}\u{0E31}\u{0E2A}\u{0E14}\u{0E35}", [0x0E2A, 'Thai']],
            'Hangul jamo'              => ["\u{1100}\u{1161}", [0x1100, 'Hangul jamo']],
            'beyond the BMP'           => ["\u{1E900}", [0x1_E900, 'right-to-left']],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function simpleProvider(): array
    {
        return [
            'Latin with combining marks' => ["cafe\u{0301} na\u{0308}ive"],
            'Greek and Cyrillic'         => ["\u{03B1}\u{03B2}\u{03B3} \u{0434}\u{0430}"],
            'Han and Kana'               => ["\u{6F22}\u{5B57} \u{304B}\u{306A}"],
            'precomposed Hangul'         => ["\u{D55C}\u{AE00}"],
        ];
    }

    #[Test]
    public function aWarningIsRecordedOncePerScript(): void
    {
        [$doc, $font] = $this->document(ScriptCheck::Warn);
        $font->shape("\u{05E9}\u{05DC}\u{05D5}\u{05DD}");
        $font->shape("\u{05D0}");
        $run = $font->shape("\u{0633}\u{0644}\u{0627}\u{0645}");

        self::assertSame(
            [
                [
                    'Hebrew text (U+05E9) needs shaping that Php2Pdf cannot do yet, so it would be set wrongly in font "DejaVuSans"; it is set unshaped.',
                    'Arabic text (U+0633) needs shaping that Php2Pdf cannot do yet, so it would be set wrongly in font "DejaVuSans"; it is set unshaped.',
                ],
                4,
            ],
            [$doc->fonts()->warnings(), $run->count()],
        );
    }

    /** @param array{0: int, 1: string} $expected */
    #[Test]
    #[DataProvider('complexProvider')]
    public function complexScriptsAreFound(string $text, array $expected): void
    {
        self::assertSame($expected, ComplexScripts::find($text));
    }

    #[Test]
    public function complexTextIsRefusedByDefault(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage(
            'Hebrew text (U+05E9) needs shaping that Php2Pdf cannot do yet, so it would be set wrongly in font "DejaVuSans".',
        );
        $this->font(null)->shape("\u{05E9}\u{05DC}\u{05D5}\u{05DD}");
    }

    #[Test]
    public function ignoringSetsTheTextSilently(): void
    {
        [$doc, $font] = $this->document(ScriptCheck::Ignore);
        $font->shape("\u{05E9}\u{05DC}\u{05D5}\u{05DD}");

        self::assertSame([], $doc->fonts()->warnings());
    }

    #[Test]
    #[DataProvider('simpleProvider')]
    public function scriptsTheEngineShapesAreLeftAlone(string $text): void
    {
        self::assertNull(ComplexScripts::find($text));
    }

    /** @return array{0: Document, 1: FontInterface} */
    private function document(?ScriptCheck $check): array
    {
        $doc = new Document(new MemoryOutput(), new DocumentOptions(scriptCheck: $check));

        return [$doc, $doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'))];
    }

    private function font(?ScriptCheck $check): FontInterface
    {
        return $this->document($check)[1];
    }
}
