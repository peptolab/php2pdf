<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Svg\Element;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ElementTest extends TestCase
{
    /** @return array<string, array{0: list<Element|string>, 1: bool}> */
    public static function contentProvider(): array
    {
        return [
            'no children'                => [[], false],
            'only blank text'            => [[" \n\t"], false],
            'text'                       => [[' a '], true],
            'an element'                 => [[new Element('tspan')], true],
            'blank text then an element' => [[' ', new Element('tspan')], true],
        ];
    }

    #[Test]
    public function aMissingChildIsNull(): void
    {
        self::assertNull((new Element('g', children: [new Element('rect')]))->child('circle'));
    }

    /** @param list<Element|string> $children */
    #[Test]
    #[DataProvider('contentProvider')]
    public function contentIsNonBlankTextOrAnElement(array $children, bool $expected): void
    {
        self::assertSame($expected, (new Element('text', children: $children))->hasContent());
    }

    #[Test]
    public function hasTellsWhetherTheAttributeIsPresent(): void
    {
        $element = new Element('rect', ['x' => '']);

        self::assertSame([true, false], [$element->has('x'), $element->has('y')]);
    }
}
