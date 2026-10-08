<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Color;

use Php2Pdf\Color\Cmyk;
use Php2Pdf\Color\GradientStop;
use Php2Pdf\Color\Gray;
use Php2Pdf\Color\IccProfile;
use Php2Pdf\Color\LinearGradient;
use Php2Pdf\Color\Spot;
use Php2Pdf\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_pad;
use function str_repeat;

#[Group('unit')]
final class ColorValidationTest extends TestCase
{
    /** @return array<string, array{0: callable(): mixed, 1: string}> */
    public static function invalidProvider(): array
    {
        return [
            'a gradient of one stop'       => [
                static fn(): LinearGradient => new LinearGradient(0.0, 0.0, 1.0, 1.0, [new GradientStop(
                    0.0,
                    new Gray(0.0),
                )]),
                'A gradient needs at least two stops.',
            ],
            'a stop before the start'      => [
                static fn(): GradientStop => new GradientStop(-0.1, new Gray(0.0)),
                'Gradient stop offset must be between 0 and 1.',
            ],
            'a stop past the end'          => [
                static fn(): GradientStop => new GradientStop(1.1, new Gray(0.0)),
                'Gradient stop offset must be between 0 and 1.',
            ],
            'a truncated ICC profile'      => [
                static fn(): IccProfile => new IccProfile('too short'),
                'ICC profile data is too short.',
            ],
            'an unsupported ICC space'     => [
                static fn(): IccProfile => new IccProfile(str_pad(
                    str_repeat("\0", times: 16) . 'HSV ',
                    length: 128,
                    pad_string: "\0",
                )),
                'Unsupported ICC colour space in profile.',
            ],
            'a spot colour without a name' => [
                static fn(): Spot => new Spot('', new Cmyk(0.0, 0.0, 0.0, 1.0)),
                'Spot colour name must not be empty.',
            ],
        ];
    }

    #[Test]
    #[DataProvider('invalidProvider')]
    public function invalidColoursAreRefused(callable $make, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $make();
    }
}
