<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Svg\Context;
use Php2Pdf\Svg\Element;
use Php2Pdf\Svg\FontResolver;
use Php2Pdf\Svg\Shapes;
use Php2Pdf\Svg\Style;
use Php2Pdf\Svg\SvgFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The geometry of SVG basic shapes. */
#[Group('unit')]
final class ShapesTest extends TestCase
{
    /** @return array<string, array{0: Element}> */
    public static function noPathProvider(): array
    {
        return [
            'not a shape'           => [new Element('g')],
            'ellipse with no radii' => [new Element('ellipse', ['cx' => '5', 'cy' => '5'])],
            'ellipse with zero rx'  => [new Element('ellipse', ['rx' => '0', 'ry' => '2'])],
        ];
    }

    #[Test]
    #[DataProvider('noPathProvider')]
    public function anElementWithoutAreaOrShapeHasNoPath(Element $element): void
    {
        $document = new Document(new MemoryOutput());
        $context  = new Context(
            $document,
            SvgFile::fromData('<svg xmlns="http://www.w3.org/2000/svg"/>'),
            new FontResolver($document, null),
        );

        self::assertNull(Shapes::path($element, Style::root(), $context));
    }
}
