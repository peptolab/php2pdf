<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text;

use Override;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Font\Font;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Textflow;
use Php2PdfTest\TestAsset\PdfTools;
use Php2PdfTest\TestAsset\TextShows;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_count_values;
use function array_intersect;
use function array_merge;
use function array_values;
use function count;
use function end;
use function preg_match;

/**
 * A box of laid-out text is one text object, its lines moved from one another (#8). What a text
 * object may not hold is drawn outside it: underlines and strikeouts after it, inline artwork and
 * colour glyph layers between text objects.
 */
#[Group('integration')]
final class TextObjectTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;
    private Font $font;

    /** @return array<string, array{0: string}> */
    public static function outsideProvider(): array
    {
        return [
            'underline and strikeout' => ['decorated'],
            'inline artwork'          => ['artwork'],
            'colour glyph layers'     => ['colour'],
        ];
    }

    #[Test]
    public function aBoxOfTextIsOneTextObjectWhoseLinesFollowEachOther(): void
    {
        $this->fit($this->doc->textflow("one\ntwo\nthree\nfour", new TextStyle(
            font: $this->font,
            size: 10.0,
        )));
        $objects = TextShows::operators($this->out->contents());

        self::assertSame([1, 3], [count($objects), array_count_values($objects[0] ?? [])['T*'] ?? 0]);
    }

    #[Test]
    public function aTopDownPageSetsItsFirstLineWithTmAndMovesTheOthersDown(): void
    {
        $flow = $this->doc->textflow("one\ntwo\nthree", new TextStyle(
            font: $this->font,
            size: 10.0,
        ));
        $this->doc->addPage(new Dimensions(300.0, 100.0), topDown: true)->fitTextflow(
            $flow,
            new Box(10.0, 10.0, 280.0, 80.0),
        );
        $this->doc->close();
        $operators = TextShows::operators($this->out->contents())[0] ?? [];

        self::assertSame(['Tm', 2], [
            array_values(array_intersect($operators, ['Td', 'Tm', 'T*']))[0] ?? null,
            array_count_values($operators)['T*'] ?? 0,
        ]);
    }

    #[Test]
    #[DataProvider('outsideProvider')]
    public function nothingButTextIsDrawnInsideATextObject(string $case): void
    {
        $this->fit(match ($case) {
            'decorated' => $this->decorated(),
            'artwork' => $this->doc
                ->textflowBuilder(new TextStyle(
                    font: $this->font,
                    size: 10.0,
                ))
                ->text('First line')
                ->lineBreak()
                ->text('Call ')
                ->inline($this->doc->template(new Dimensions(10.0, 10.0)), height: 8.0)
                ->text(' now')
                ->build(),
            default     => $this->doc->textflow('AB A', new TextStyle(
                font: $this->doc->fonts()->load(PdfTools::assetPath('fonts/PhpPdfColourTest.ttf')),
                size: 10.0,
            )),
        });

        self::assertSame(
            [],
            array_intersect(array_merge(...TextShows::operators($this->out->contents())), [
                'q',
                'Q',
                're',
                'f',
                'cm',
                'Do',
            ]),
        );
    }

    #[Test]
    public function textLinesStartWhereTheLayoutPutsThem(): void
    {
        $flow = $this->doc->textflow("one\ntwo\nthree\nfour", new TextStyle(
            font: $this->font,
            size: 10.0,
            leading: null,
        ));
        $this->fit($flow);
        $shows = TextShows::of($this->out->contents());
        $last  = end($shows);

        self::assertEqualsWithDelta(
            $flow->lastFit()?->lastBaseline,
            false === $last ? null : [$last[0], $last[1]],
            1.0e-6,
        );
    }

    #[Test]
    public function underlinesAndStrikeoutsFollowTheTextInTheirSegmentsColours(): void
    {
        $this->fit($this->decorated());

        self::assertSame(
            1,
            preg_match('/\nET\n[^\n]+ re\nf\n1 0 0 rg\n[^\n]+ re\nf\n0 g\n[^\n]+ re\nf\nQ\n/', $this->out->contents()),
            'Black, red and black again, after the text.',
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->out  = new MemoryOutput();
        $this->doc  = new Document($this->out, new DocumentOptions(compress: false));
        $this->font = $this->doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
    }

    /** A line underlined in black, then red, then struck out in black. */
    private function decorated(): Textflow
    {
        return $this->doc
            ->textflowBuilder(new TextStyle(
                font: $this->font,
                size: 10.0,
            ))
            ->text('plain ', new TextStyle(underline: true))
            ->text('red', new TextStyle(
                underline: true,
                color: Rgb::fromHex('#ff0000'),
            ))
            ->text(' plain', new TextStyle(strikeout: true))
            ->build();
    }

    private function fit(Textflow $flow): void
    {
        $this->doc->addPage(new Dimensions(300.0, 100.0))->fitTextflow($flow, new Box(10.0, 10.0, 280.0, 80.0));
        $this->doc->close();
    }
}
