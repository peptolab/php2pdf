<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Color;

use Php2Pdf\Color\Cmyk;
use Php2Pdf\Color\DeviceN;
use Php2Pdf\Color\Gray;
use Php2Pdf\Color\Spot;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function preg_match;
use function preg_match_all;
use function preg_quote;

/** DeviceN colours (#37). */
#[Group('unit')]
final class DeviceNTest extends TestCase
{
    /** @return array<string, array{0: callable(): mixed, 1: string}> */
    public static function invalidProvider(): array
    {
        $orange = new Spot('Fictional Orange', new Cmyk(0.0, 0.5, 1.0, 0.0));

        return [
            'no colorants'     => [static fn(): DeviceN => new DeviceN([]), '1 to 32 colorants, not 0'],
            'too many'         => [
                static fn(): DeviceN => new DeviceN(array_fill(0, count: 33, value: $orange)),
                '1 to 32 colorants, not 33',
            ],
            'a name twice'     => [
                static fn(): DeviceN => new DeviceN([$orange, $orange]),
                '"Fictional Orange" appears twice',
            ],
            'mixed alternates' => [
                static fn(): DeviceN => new DeviceN([$orange, new Spot('Grey Ink', new Gray(0.5))]),
                'alternates in one colour space',
            ],
            'a tint short'     => [
                static fn(): DeviceN => (new DeviceN([DeviceN::cyan(), $orange]))->withTints(0.5),
                '2 colorants, 1 tints',
            ],
        ];
    }

    #[Test]
    public function aMixIsDefinedOncePerSetOfInks(): void
    {
        $color  = new DeviceN([DeviceN::cyan(), DeviceN::magenta()]);
        $out    = new MemoryOutput();
        $doc    = new Document($out, new DocumentOptions(compress: false));
        $canvas = $doc->addPage(PageSize::A4)->canvas();
        $canvas->setFillColor($color)->setFillColor($color->withTints(0.1, 0.2))->setStrokeColor($color);
        $doc->close();

        self::assertSame(1, preg_match_all('#/FunctionType 4#', $out->contents()));
    }

    #[Test]
    public function componentsAreTheColorantTints(): void
    {
        $color = (new DeviceN([DeviceN::cyan(), DeviceN::black()]))->withTints(0.25, 0.75);

        self::assertSame([2, [0.25, 0.75]], [$color->componentCount(), $color->components()]);
    }

    #[Test]
    #[DataProvider('invalidProvider')]
    public function invalidColoursAreRefused(callable $make, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $make();
    }

    #[Test]
    public function spotColorantsAreListedWithTheirSeparations(): void
    {
        $pdf = $this->draw(new DeviceN([
            DeviceN::cyan(0.2),
            new Spot('Fictional Orange', new Cmyk(0.0, 0.5, 1.0, 0.0), 0.7),
        ]));

        self::assertSame(
            [1, 1, 1],
            [
                preg_match(
                    '#\[/DeviceN \[/Cyan /Fictional\#20Orange\] /DeviceCMYK \d+ 0 R <</Colorants<</Fictional\#20Orange (\d+) 0 R>>>>\]#',
                    $pdf,
                ),
                preg_match('#\[/Separation /Fictional\#20Orange /DeviceCMYK#', $pdf),
                preg_match("#/CS1 cs 0\\.2 0\\.7 scn\n#", $pdf),
            ],
        );
    }

    #[Test]
    public function theTintTransformFiltersAdditiveInks(): void
    {
        $pdf = $this->draw(new DeviceN([new Spot('Grey Ink', new Gray(0.25)), new Spot('Dark Ink', new Gray(0.0))]));

        self::assertStringContainsString(
            "{\n1 2 index 0.75 mul 1 exch sub mul 1 index 1 mul 1 exch sub mul\n3 1 roll pop pop\n}",
            $pdf,
        );
    }

    #[Test]
    public function theTintTransformMixesSubtractiveInks(): void
    {
        $pdf = $this->draw(new DeviceN([
            DeviceN::cyan(),
            new Spot('Fictional Orange', new Cmyk(0.0, 0.5, 1.0, 0.0)),
        ]));
        $code =
            "{\n"
            . "1 2 index 1 mul 1 exch sub mul 1 exch sub\n"
            . "1 2 index 0.5 mul 1 exch sub mul 1 exch sub\n"
            . "1 3 index 1 mul 1 exch sub mul 1 exch sub\n"
            . "1 1 exch sub\n"
            . "6 4 roll pop pop\n}";

        self::assertSame(
            1,
            preg_match(
                '#/FunctionType 4/Domain\[0 1 0 1\]/Range\[0 1 0 1 0 1 0 1\]/Length \d+>>\nstream\n'
                    . preg_quote($code, delimiter: '#')
                    . '#',
                $pdf,
            ),
        );
    }

    private function draw(DeviceN $color): string
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(compress: false));
        $doc->addPage(PageSize::A4)->canvas()->setFillColor($color)->rect(0.0, 0.0, 10.0, 10.0)->fill();
        $doc->close();

        return $out->contents();
    }
}
