<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Color;

use PhpPdf\Color\ColorInterface;
use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Document;
use PhpPdf\Document\PageSize;
use PhpPdf\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** ColorInterface is sealed: the canvas writes only the library's own colours (#28). */
#[Group('unit')]
final class ForeignColorTest extends TestCase
{
    #[Test]
    public function aColourFromElsewhereIsRefusedClearly(): void
    {
        $colour = new class implements ColorInterface {
            public function componentCount(): int
            {
                return 1;
            }

            public function components(): array
            {
                return [0.5];
            }
        };
        $canvas = (new Document(new MemoryOutput()))->addPage(PageSize::A4)
            ->canvas();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Colours must be Gray, Rgb, Cmyk, Spot or IccColor;');
        $canvas->setFillColor($colour);
    }
}
