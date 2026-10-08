<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Text\FlowOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FlowOptionsTest extends TestCase
{
    /** @return array<string, array{0: float}> */
    public static function shrinkLimitProvider(): array
    {
        return [
            'zero'          => [0.0],
            'negative'      => [-0.5],
            'more than one' => [1.5],
        ];
    }

    #[Test]
    #[DataProvider('shrinkLimitProvider')]
    public function refusesAShrinkLimitOutsideZeroToOne(float $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The shrink limit must be between 0 and 1.');
        FlowOptions::shrinkToFit($limit);
    }
}
