<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font;

use Override;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2PdfTest\TestAsset\Font\SfntTables;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function preg_match;
use function substr_replace;

/** The font descriptor's flags, and a variable font whose CFF2 outlines cannot be converted. */
#[Group('integration')]
final class Type0FontWriterTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;

    /** @return array<string, array{0: int, 1: string, 2: int}> */
    public static function postTableProvider(): array
    {
        return [
            'unchanged'    => [12, "\x00\x00\x00\x00", 34],
            'fixed pitch'  => [12, "\x00\x00\x00\x01", 35],
            'italic angle' => [4, "\xFF\xF4\x00\x00", 98],
        ];
    }

    private static function font(string $asset): string
    {
        return (string) file_get_contents(PdfTools::assetPath($asset));
    }

    #[Test]
    public function aVariableFontWhoseCff2OutlinesCannotBeConvertedIsRefused(): void
    {
        $program = OpenTypeFont::fromData(SfntTables::replace(self::font('fonts/PhpPdfVariable.otf'), [
            'CFF2' => "\x02\x00\x05\x00\x00",
        ]));

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage(
            'Variable font "SourceSans3-Regular" cannot be embedded: its CFF2 outlines could not be converted: '
                . 'The font data is cut short: it has no 4 bytes at offset 5.',
        );

        $this->draw($program);
    }

    #[Test]
    #[DataProvider('postTableProvider')]
    public function theDescriptorFlagsFollowThePostTable(int $offset, string $bytes, int $flags): void
    {
        $data = self::font('fonts/SourceSerif4-Regular.ttf');
        $post = (string) OpenTypeFont::fromData($data)->table('post');
        $pdf  = $this->draw(OpenTypeFont::fromData(SfntTables::replace($data, [
            'post' => substr_replace($post, $bytes, $offset, length: 4),
        ])));
        $match = [];
        preg_match('#/Flags (\d+)#', $pdf, $match);

        self::assertSame((string) $flags, $match[1] ?? null);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
        ));
    }

    private function draw(OpenTypeFont $program): string
    {
        $this->doc
            ->addPage(PageSize::A4)
            ->canvas()
            ->drawText('Hello', $this->doc->fonts()->embed($program), 12.0, 10.0, 10.0);
        $this->doc->close();

        return $this->out->contents();
    }
}
