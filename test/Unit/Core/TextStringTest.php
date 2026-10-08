<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core;

use DateTimeImmutable;
use Php2Pdf\Core\TextString;
use Php2Pdf\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class TextStringTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function dates(): array
    {
        return [
            'UTC'         => ['2026-01-02T03:04:05+00:00', 'D:20260102030405Z'],
            'east of UTC' => ['2026-01-02T03:04:05+10:30', "D:20260102030405+10'30'"],
            'west of UTC' => ['2026-01-02T03:04:05-05:00', "D:20260102030405-05'00'"],
        ];
    }

    #[Test]
    #[DataProvider('dates')]
    public function formatsDatesWithTheirUtcOffset(string $date, string $expected): void
    {
        self::assertSame($expected, TextString::date(new DateTimeImmutable($date)));
    }

    #[Test]
    public function refusesTextThatIsNotValidUtf8(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Text is not valid UTF-8.');
        TextString::encode("caf\xE9");
    }
}
