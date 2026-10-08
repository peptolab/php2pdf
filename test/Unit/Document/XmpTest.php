<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Document;

use Closure;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\Info;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class XmpTest extends TestCase
{
    /** @return array<string, array{0: Closure(Info): Info, 1: string}> */
    public static function infoFieldProvider(): array
    {
        return [
            'subject'  => [
                static fn(Info $info): Info => $info->setSubject('A & B'),
                '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">A &amp; B</rdf:li></rdf:Alt></dc:description>',
            ],
            'keywords' => [
                static fn(Info $info): Info => $info->setKeywords('pdf, tests'),
                '<pdf:Keywords>pdf, tests</pdf:Keywords>',
            ],
            'creator'  => [
                static fn(Info $info): Info => $info->setCreator('<Designer>'),
                '<xmp:CreatorTool>&lt;Designer&gt;</xmp:CreatorTool>',
            ],
        ];
    }

    /** @param Closure(Info): Info $set */
    #[Test]
    #[DataProvider('infoFieldProvider')]
    public function writesDocumentInformationToXmp(Closure $set, string $expected): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
        ));
        $set($doc->info());
        $doc->addPage();
        $doc->close();

        self::assertStringContainsString($expected, $out->contents());
    }
}
