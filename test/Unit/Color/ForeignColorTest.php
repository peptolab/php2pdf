<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Color;

use Override;
use Php2Pdf\Color\ColorInterface;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\InvalidArgumentException;
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
            #[Override]
            public function componentCount(): int
            {
                return 1;
            }

            #[Override]
            public function components(): array
            {
                return [0.5];
            }
        };
        $canvas = (new Document(new MemoryOutput()))->addPage(PageSize::A4)
            ->canvas();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Colours must be Gray, Rgb, Cmyk, Lab, Spot, DeviceN or IccColor;');
        $canvas->setFillColor($colour);
    }
}
