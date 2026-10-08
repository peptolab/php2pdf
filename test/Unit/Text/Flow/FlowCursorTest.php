<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Flow;

use Php2Pdf\Text\Flow\FlowCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FlowCursorTest extends TestCase
{
    /** @return array<string, array{0: FlowCursor, 1: bool}> */
    public static function cursorProvider(): array
    {
        return [
            'the first item of a later paragraph' => [new FlowCursor(paragraph: 2), true],
            'a later item'                        => [new FlowCursor(item: 3), false],
            'part way into the first item'        => [new FlowCursor(carry: 4), false],
        ];
    }

    #[Test]
    #[DataProvider('cursorProvider')]
    public function knowsWhetherItIsAtTheStartOfAParagraph(FlowCursor $cursor, bool $atStart): void
    {
        self::assertSame($atStart, $cursor->atParagraphStart());
    }
}
