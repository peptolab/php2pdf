<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text;

use Php2Pdf\Core\Num;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Template\Template;
use Php2Pdf\Text\FitInfo;
use Php2Pdf\Text\FlowOptions;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Textflow;
use Php2Pdf\Text\TextFormat;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sprintf;

/** Artwork set in the text of a Textflow, on the baseline (#24). */
#[Group('integration')]
final class InlineObjectTest extends TestCase
{
    private Document $doc;
    private MemoryOutput $out;
    private FontInterface $font;
    private Template $icon;
    private TextStyle $style;

    /** @return array<string, array{0: string, 1: float}> width the icon takes, from its attributes */
    public static function sizeProvider(): array
    {
        return [
            'height keeps proportions' => ['height="8"', 16.0],
            'width keeps proportions'  => ['width="12"', 12.0],
            'both as given'            => ['width="5" height="8"', 5.0],
            'one em high by default'   => ['', 20.0],
        ];
    }

    private static function num(float $value): string
    {
        return Num::format($value);
    }

    #[Test]
    public function anIconWiderThanTheLineGetsALineOfItsOwn(): void
    {
        $info = $this->fit('Wide <img src="icon" width="300"> icon', width: 100.0);

        self::assertSame(3, $info->lineCount);
    }

    #[Test]
    public function anImageMustBeDefined(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No inline object named "nothing"');
        $this->fit('<img src="nothing">');
    }

    #[Test]
    public function anImageNeedsASource(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('An <img> in text markup needs a src');
        $this->fit('<img height="8">');
    }

    #[Test]
    public function aTallIconMakesRoomAboveTheLine(): void
    {
        $info = $this->fit('<img src="icon" height="30"> tall');

        self::assertEqualsWithDelta(750.0 - 30.0, $info->firstBaseline[1] ?? 0.0, 1.0e-6);
    }

    /** The leading is 12 pt; an icon 30 pt high pushes its line down by how far it rises above the text. */
    #[Test]
    public function aTallIconPushesItsLineDown(): void
    {
        $info = $this->fit('First line<br>second <img src="icon" height="30"> line');

        self::assertEqualsWithDelta(
            12.0 + (30.0 - $this->font->ascender(10.0)),
            ($info->firstBaseline[1] ?? 0.0) - ($info->lastBaseline[1] ?? 0.0),
            1.0e-6,
        );
    }

    #[Test]
    public function copyFittingScalesTheIconWithTheText(): void
    {
        $flow = $this->doc->textflow('Call <img src="icon" height="8"> now', $this->style, TextFormat::Markup);
        $this->doc->addPage()->fitTextflow($flow, new Box(50.0, 500.0, 400.0, 9.0), new FlowOptions(shrinkToFit: true));
        $info  = $flow->lastFit();
        $scale = $info->scale ?? 1.0;
        self::assertLessThan(1.0, $scale);

        self::assertEqualsWithDelta(
            50.0
                + $this->font->width('Call ', 10.0 * $scale)
                + (16.0 * $scale)
                + $this->font->width(' now', 10.0 * $scale),
            $info?->textEnd[0] ?? 0.0,
            1.0e-6,
        );
    }

    #[Test]
    public function theBuilderSetsArtworkInTheText(): void
    {
        $flow = $this->doc
            ->textflowBuilder($this->style)
            ->text('Call ')
            ->inline($this->icon, height: 8.0)
            ->text(' now')
            ->build();
        $info = $this->fitFlow($flow, 400.0);

        self::assertEqualsWithDelta(
            50.0 + $this->font->width('Call ', 10.0) + 16.0 + $this->font->width(' now', 10.0),
            $info->textEnd[0] ?? 0.0,
            1.0e-6,
        );
    }

    #[Test]
    public function theIconSitsOnTheBaseline(): void
    {
        $info = $this->fit('Call <img src="icon" height="8" raise="2"> now');
        $y    = $info->firstBaseline[1] ?? 0.0;

        self::assertStringContainsString(
            sprintf(
                'q 0.8 0 0 0.8 %s %s cm /',
                self::num(50.0 + $this->font->width('Call ', 10.0)),
                self::num($y + 2.0),
            ),
            $this->contents(),
        );
    }

    #[Test]
    #[DataProvider('sizeProvider')]
    public function theIconTakesItsWidthInTheLine(string $attributes, float $width): void
    {
        $info = $this->fit("Call <img src=\"icon\" {$attributes}> now");

        self::assertEqualsWithDelta(
            50.0 + $this->font->width('Call ', 10.0) + $width + $this->font->width(' now', 10.0),
            $info->textEnd[0] ?? 0.0,
            1.0e-6,
        );
    }

    protected function setUp(): void
    {
        $this->out   = new MemoryOutput();
        $this->doc   = new Document($this->out, new DocumentOptions(compress: false));
        $this->font  = $this->doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $this->style = new TextStyle(
            font: $this->font,
            size: 10.0,
        );
        $this->icon = $this->doc->template(new Dimensions(20.0, 10.0));
        $this->doc->inlines()->define('icon', $this->icon);
    }

    private function contents(): string
    {
        $this->doc->close();

        return $this->out->contents();
    }

    private function fit(string $markup, float $width = 400.0): FitInfo
    {
        return $this->fitFlow($this->doc->textflow($markup, $this->style, TextFormat::Markup), $width);
    }

    private function fitFlow(Textflow $flow, float $width): FitInfo
    {
        $this->doc->addPage()->fitTextflow($flow, new Box(50.0, 500.0, $width, 250.0));
        $info = $flow->lastFit();
        self::assertNotNull($info);

        return $info;
    }
}
