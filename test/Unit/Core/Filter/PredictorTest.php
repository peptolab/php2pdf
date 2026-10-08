<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core\Filter;

use Php2Pdf\Core\Filter\Predictor;
use Php2Pdf\Exception\ParseException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class PredictorTest extends TestCase
{
    #[Test]
    public function leavesDataAloneWithoutAPredictor(): void
    {
        self::assertSame("\x01\x02\x03", Predictor::decode(
            "\x01\x02\x03",
            predictor: 1,
            colors: 1,
            bitsPerComponent: 8,
            columns: 3,
        ));
    }

    #[Test]
    public function rejectsAnUnknownPredictor(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unsupported predictor 3.');

        Predictor::decode("\x01\x02\x03", predictor: 3, colors: 1, bitsPerComponent: 8, columns: 3);
    }

    #[Test]
    public function rejectsTheTiffPredictorBelowEightBitsPerComponent(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('TIFF predictor is only supported for 8 and 16 bits per component.');

        Predictor::decode("\x01\x02\x03", predictor: 2, colors: 1, bitsPerComponent: 4, columns: 6);
    }
}
