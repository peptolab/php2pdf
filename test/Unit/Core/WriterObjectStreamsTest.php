<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Core\Writer;
use Php2Pdf\Exception\StateException;
use Php2PdfTest\TestAsset\XrefRows;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_values;
use function count;
use function explode;
use function gzuncompress;
use function preg_match;
use function range;
use function strlen;
use function substr;
use function trim;

/**
 * Objects other than streams written a hundred at a time in object streams, found through a
 * cross-reference stream that carries the trailer (PDF 1.5).
 */
#[Group('unit')]
final class WriterObjectStreamsTest extends TestCase
{
    /** 150 dictionaries and one stream among them, the second dictionary the catalog. */
    private static function document(): string
    {
        $out    = new MemoryOutput();
        $writer = self::writer($out);
        $root   = null;
        for ($i = 0; $i < 150; ++$i) {
            $ref  = $writer->add(Dictionary::of(['Type' => new Name(1 === $i ? 'Catalog' : 'Thing'), 'N' => $i]));
            $root = 1 === $i ? $ref : $root;
            if (75 === $i) {
                $writer->add(Stream::of('content'));
            }
        }
        $writer->finish(Dictionary::of(['Root' => $root]));

        return $out->contents();
    }

    /**
     * The object at a byte offset: its dictionary, and its stream's data inflated.
     *
     * @return array{0: string, 1: string}
     */
    private static function streamAt(string $pdf, int $offset): array
    {
        $m = [];
        self::assertSame(1, preg_match('/\G\d+ 0 obj\n(<<.*?>>)\nstream\n/s', $pdf, $m, offset: $offset));
        $dict   = $m[1] ?? '';
        $length = [];
        self::assertSame(1, preg_match('/\/Length (\d+)/', $dict, $length));
        $data = (string) gzuncompress(substr($pdf, $offset + strlen($m[0] ?? ''), (int) ($length[1] ?? 0)));

        return [$dict, $data];
    }

    /** A writer with object streams on, and what it wrote. */
    private static function writer(MemoryOutput $out): Writer
    {
        $writer = new Writer($out, compress: true, compressionLevel: 6, objectStreams: true);
        $writer->writeHeader('1.7');

        return $writer;
    }

    /**
     * The cross-reference stream's dictionary and entries, found from startxref.
     *
     * @return array{0: string, 1: list<array{0: int, 1: int, 2: int}>}
     */
    private static function xref(string $pdf): array
    {
        $m = [];
        self::assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $m));
        [$dict, $data] = self::streamAt($pdf, (int) ($m[1] ?? 0));
        $w = [];
        self::assertSame(1, preg_match('/\/W\[(\d+) (\d+) (\d+)\]/', $dict, $w));

        return [$dict, XrefRows::decode($data, [(int) ($w[1] ?? 0), (int) ($w[2] ?? 0), (int) ($w[3] ?? 0)])];
    }

    #[Test]
    public function anObjectHeldForAnObjectStreamCountsAsWritten(): void
    {
        $writer = self::writer(new MemoryOutput());
        $held   = $writer->add(Dictionary::of(['A' => 1]));
        self::assertTrue($writer->isWritten($held));

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('has already been written');
        $writer->write($held, 2);
    }

    #[Test]
    public function everyObjectIsFoundThroughTheCrossReferenceStream(): void
    {
        $pdf = self::document();
        [, $entries] = self::xref($pdf);
        $found = [];
        foreach ($entries as $id => [$type, $at, $index]) {
            if (1 === $type && substr($pdf, $at, strlen("{$id} 0 obj")) === "{$id} 0 obj") {
                $found[$id] = true;
            }
            if (2 === $type) {
                [$dict, $data] = self::streamAt($pdf, $entries[$at][1] ?? 0);
                $first = [];
                preg_match('/\/First (\d+)/', $dict, $first);
                $header = explode(' ', trim(substr($data, offset: 0, length: (int) ($first[1] ?? 0))));
                if ((string) $id === ($header[2 * $index] ?? null)) {
                    $found[$id] = true;
                }
            }
        }

        self::assertSame(range(1, count($entries) - 1), array_keys($found));
    }

    #[Test]
    public function objectsOtherThanStreamsAreWrittenAHundredToAnObjectStream(): void
    {
        $pdf = self::document();
        [, $all] = self::xref($pdf);
        $counts = [];
        foreach ($all as [$type, $stream]) {
            if (2 !== $type) {
                continue;
            }

            $counts[$stream] = ($counts[$stream] ?? 0) + 1;
        }

        self::assertSame([100, 50], array_values($counts));
    }

    #[Test]
    public function refusesUnwrittenAllocations(): void
    {
        $writer = self::writer(new MemoryOutput());
        $writer->add(Dictionary::of(['A' => 1]));
        $writer->allocate();

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('Object 2 was allocated but never written.');
        $writer->finish(new Dictionary());
    }

    #[Test]
    public function theCrossReferenceStreamCarriesTheTrailer(): void
    {
        [$dict, $entries] = self::xref(self::document());

        self::assertSame(
            [1, 1, 1, 1],
            [
                preg_match('/\/Type\/XRef/', $dict),
                preg_match('/\/Root 2 0 R/', $dict),
                preg_match('/\/Size ' . count($entries) . '\b/', $dict),
                preg_match('/\/ID\[<[0-9a-f]{32}> <[0-9a-f]{32}>\]/', $dict),
            ],
        );
    }
}
