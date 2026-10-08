<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Canvas;

use Php2Pdf\Canvas\TextlineOptions;
use Php2Pdf\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class TextlineOptionsTest extends TestCase
{
    /** @return array<string, array{0: float}> */
    public static function invalidShrinkLimitProvider(): array
    {
        return [
            'zero'      => [0.0],
            'above one' => [1.5],
        ];
    }

    #[Test]
    #[DataProvider('invalidShrinkLimitProvider')]
    public function rejectsAShrinkLimitOutsideZeroToOne(float $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The shrink limit must be between 0 and 1.');
        TextlineOptions::shrinkToFit(null, $limit);
    }
}
