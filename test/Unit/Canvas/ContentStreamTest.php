<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Canvas;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Exception\StateException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ContentStreamTest extends TestCase
{
    #[Test]
    public function aFinishedStreamCannotBeFinishedAgain(): void
    {
        $canvas = (new Document(new MemoryOutput()))->addPage()->canvas();
        $canvas->finish();

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('Canvas has already been finished.');
        $canvas->finish();
    }
}
