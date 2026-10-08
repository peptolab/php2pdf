<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Exception\ParseException;
use Php2Pdf\Import\Lexer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class LexerTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function lineContinuationProvider(): array
    {
        return [
            'carriage return and line feed' => ["(a\\\r\nb)"],
            'carriage return alone'         => ["(a\\\rb)"],
        ];
    }

    #[Test]
    #[DataProvider('lineContinuationProvider')]
    public function aBackslashBeforeALineEndJoinsTheLines(string $syntax): void
    {
        self::assertSame('ab', (new Lexer($syntax))->next()->value);
    }

    #[Test]
    public function aStringEndingInABackslashIsUnterminated(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unterminated string at offset 0.');
        (new Lexer('(a\\'))->next();
    }
}
