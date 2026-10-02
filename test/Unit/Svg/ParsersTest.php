<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Exception\PdfException;
use Php2Pdf\Svg\ColorParser;
use Php2Pdf\Svg\Length;
use Php2Pdf\Svg\PathParser;
use Php2Pdf\Svg\StyleSheet;
use Php2Pdf\Svg\TransformParser;
use Php2Pdf\Svg\XmlParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

#[Group('unit')]
final class ParsersTest extends TestCase
{
    #[Test]
    public function arcsBecomeCubicsAndCurveBoundsAreExact(): void
    {
        // A full circle of radius 10 as two arcs.
        $path   = PathParser::parse('M0 10 A10 10 0 1 1 20 10 A10 10 0 1 1 0 10');
        $bounds = $path->bounds();
        self::assertNotNull($bounds);
        self::assertEqualsWithDelta(0.0, $bounds->x, 1.0e-3);
        self::assertEqualsWithDelta(0.0, $bounds->y, 1.0e-3);
        self::assertEqualsWithDelta(20.0, $bounds->width, 1.0e-3);
        self::assertEqualsWithDelta(20.0, $bounds->height, 1.0e-3);
        self::assertGreaterThanOrEqual(5, count($path->commands));
    }

    #[Test]
    public function coloursParseInEveryNotation(): void
    {
        self::assertSame([1.0, 0.0, 0.0], ColorParser::color('red')?->components());
        self::assertSame([1.0, 0.0, 0.0], ColorParser::color('#F00')?->components());
        self::assertSame([0.0, 0.0, 1.0], ColorParser::color('#0000ffcc')?->components());
        self::assertEqualsWithDelta(
            [0.2, 0.4, 0.6],
            ColorParser::color('rgb(51, 102, 153)')?->components() ?? [],
            1.0e-9,
        );
        self::assertSame([0.5, 1.0, 0.0], ColorParser::color('rgb(50%, 100%, 0%)')?->components());
        self::assertNull(ColorParser::color('transparentish'));

        $paint = ColorParser::paint('url(#grad) blue');
        self::assertNotNull($paint);
        self::assertSame('grad', $paint->serverId);
        self::assertSame([0.0, 0.0, 1.0], $paint->color?->components());
        self::assertTrue(ColorParser::paint('none')?->isNone());
    }

    #[Test]
    public function lengthsConvertUnitsAndPercentages(): void
    {
        self::assertSame(96.0, Length::parse('1in'));
        self::assertSame(16.0, Length::parse('12pt'));
        self::assertSame(32.0, Length::parse('2em', 0.0, 16.0));
        self::assertSame(25.0, Length::parse('50%', 50.0));
        self::assertSame(3.0, Length::parse('3'));
        self::assertNull(Length::parse('auto'));
        self::assertNull(Length::parse('12 bananas'));
        self::assertSame([1.0, -2.5, 3.0e2], Length::numbers('1,-2.5 3e2'));
    }

    #[Test]
    public function pathParserHandlesEveryCommandAndRelativeForms(): void
    {
        $path = PathParser::parse(
            'M10 10 h 20 v 20 H 10 Z m 5 5 l 10 0 c 0 0 5 5 5 5 s 5 5 10 0 q 5 5 10 0 t 10 0 a 5 5 0 0 1 10 0',
        );
        $ops = [];
        foreach ($path->commands as [$op]) {
            $ops[] = $op;
        }
        self::assertSame(['M', 'L', 'L', 'L', 'Z', 'M', 'L', 'C', 'C', 'C', 'C', 'C', 'C', 'L'], $ops);
        $bounds = $path->bounds();
        self::assertNotNull($bounds);
        self::assertSame(10.0, $bounds->x);
        self::assertSame(10.0, $bounds->y);
        self::assertEqualsWithDelta(70.0, $bounds->right(), 1.0e-6);
    }

    #[Test]
    public function styleSheetsCascadeBySpecificityAndOrder(): void
    {
        $sheet = StyleSheet::fromCss(<<<'CSS'
            /* comment */
            rect { fill: red; stroke: blue }
            .a { fill: green !important; }
            #id { fill: black }
            rect.a.b { stroke: purple }
            @media print { rect { fill: white } }
            g > rect { fill: orange }
            CSS);
        $plain = XmlParser::parse('<svg><rect/></svg>')->child('rect');
        self::assertNotNull($plain);
        self::assertSame(['fill' => 'red', 'stroke' => 'blue'], $sheet->match($plain));

        $classed = XmlParser::parse('<svg><rect class="b a"/></svg>')->child('rect');
        self::assertNotNull($classed);
        self::assertSame(['fill' => 'green', 'stroke' => 'purple'], $sheet->match($classed));

        $byId = XmlParser::parse('<svg><rect id="id" class="a"/></svg>')->child('rect');
        self::assertNotNull($byId);
        self::assertSame('black', $sheet->match($byId)['fill'] ?? null);
        self::assertSame(
            ['fill' => 'red', 'font-size' => '12px'],
            StyleSheet::declarations('fill:red; font-size : 12px;;'),
        );
    }

    #[Test]
    public function transformsComposeInSvgOrder(): void
    {
        $m = TransformParser::parse('translate(10 20) scale(2)');
        self::assertSame([30.0, 40.0], $m->apply(10.0, 10.0));

        $r = TransformParser::parse('rotate(90 5 5)');
        [$x, $y] = $r->apply(10.0, 5.0);
        self::assertEqualsWithDelta(5.0, $x, 1.0e-9);
        self::assertEqualsWithDelta(10.0, $y, 1.0e-9);

        $matrix = TransformParser::parse('matrix(1 0 0 1 7 8) skewX(45)');
        self::assertEqualsWithDelta(1.0, $matrix->c, 1.0e-9);
        self::assertSame(7.0, $matrix->e);
        self::assertTrue(TransformParser::parse('bogus(1)')->isIdentity());
    }

    #[Test]
    public function xmlParserBuildsATreeWithEntitiesCdataAndComments(): void
    {
        $root = XmlParser::parse(<<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "x.dtd" [ <!ENTITY foo "bar"> ]>
            <!-- comment > with angle -->
            <svg:svg xmlns:svg="http://www.w3.org/2000/svg" width="10" height='20'>
              <g id="a" class="x y"><rect x="1" y="2"/></g>
              <text>A &amp; B &lt;&#65;&#x42;&gt;<![CDATA[<raw & text>]]></text>
              <style>/* css */</style>
            </svg:svg>
            XML);
        self::assertSame('svg', $root->name);
        self::assertSame(['width' => '10', 'height' => '20', 'xmlns:svg' => 'http://www.w3.org/2000/svg'], [
            'width'     => $root->attribute('width'),
            'height'    => $root->attribute('height'),
            'xmlns:svg' => $root->attribute('xmlns:svg'),
        ]);
        $g = $root->child('g');
        self::assertNotNull($g);
        self::assertSame('a', $g->id());
        self::assertSame('rect', ($g->elements()[0] ?? null)?->name);
        self::assertSame('A & B <AB><raw & text>', $root->child('text')?->text());
    }

    #[Test]
    public function xmlParserRejectsMalformedInput(): void
    {
        $this->expectException(PdfException::class);
        XmlParser::parse('<svg><rect></svg');
    }
}
