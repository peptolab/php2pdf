<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Exception\ParseException;
use Php2Pdf\Svg\XmlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class XmlParserTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function unterminatedProvider(): array
    {
        return [
            'a CDATA section'           => ['<svg><![CDATA[text</svg>', 'Unterminated CDATA section.'],
            'a DOCTYPE internal subset' => ['<!DOCTYPE svg [ <!ENTITY a "b"> <svg/>', 'Unterminated DOCTYPE.'],
            'a declaration'             => ['<svg/><!DOCTYPE svg', 'Unterminated declaration.'],
        ];
    }

    #[Test]
    #[DataProvider('unterminatedProvider')]
    public function unterminatedMarkupIsRejected(string $xml, string $message): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($message);

        XmlParser::parse($xml);
    }
}
