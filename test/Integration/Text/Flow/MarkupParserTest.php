<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text\Flow;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\TextFormat;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Faces are told apart by width: each of the four is a different font. */
#[Group('integration')]
final class MarkupParserTest extends TestCase
{
    private Document $doc;

    /** @return array<string, array{0: string}> */
    public static function boldItalicProvider(): array
    {
        return [
            'bold inside italic' => ['<p><i><b>x</b></i></p>'],
            'italic inside bold' => ['<p><b><i>x</i></b></p>'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function characterReferenceProvider(): array
    {
        return [
            'the null character' => ['<p>a&#0;</p>'],
            'a lone surrogate'   => ['<p>a&#xD800;</p>'],
        ];
    }

    #[Test]
    #[DataProvider('boldItalicProvider')]
    public function boldAndItalicTogetherTakeTheBoldItalicFace(string $markup): void
    {
        self::assertSame(
            $this->doc->fonts()->find('SourceSerif4-Bold')->width('x', 10.0),
            $this->doc->textflow($markup, $this->style(), TextFormat::Markup)->maxContentWidth(),
        );
    }

    #[Test]
    public function closingAnOuterTagClosesTheTagsOpenedInsideIt(): void
    {
        self::assertSame(
            $this->doc->textflow('<p><b>b<i>i</i></b>r</p>', $this->style(), TextFormat::Markup)->maxContentWidth(),
            $this->doc->textflow('<p><b>b<i>i</b>r</p>', $this->style(), TextFormat::Markup)->maxContentWidth(),
        );
    }

    #[Test]
    #[DataProvider('characterReferenceProvider')]
    public function refusesACharacterReferenceToAnInvalidCodePoint(string $markup): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unsupported character reference in text markup.');
        $this->doc->textflow($markup, $this->style(), TextFormat::Markup);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->doc = new Document(new MemoryOutput(), new DocumentOptions(compress: false));
        $this->doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'));
    }

    private function style(): TextStyle
    {
        return new TextStyle(
            font: 'DejaVuSans',
            bold: 'DejaVuSans-Bold',
            italic: 'SourceSans3-Regular',
            boldItalic: 'SourceSerif4-Bold',
            size: 10.0,
        );
    }
}
