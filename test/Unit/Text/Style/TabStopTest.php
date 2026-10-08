<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Style;

use Php2Pdf\Text\Style\TabAlignment;
use Php2Pdf\Text\Style\TabStop;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class TabStopTest extends TestCase
{
    #[Test]
    public function aCentreStopCentresTextOnItsPosition(): void
    {
        $stop = TabStop::center(72.0, '.');

        self::assertSame([72.0, TabAlignment::Center, '.'], [$stop->position, $stop->alignment, $stop->leader]);
    }
}
