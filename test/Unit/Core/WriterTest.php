<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Core\Writer;
use Php2Pdf\Exception\StateException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function gzuncompress;
use function preg_match;
use function preg_match_all;
use function str_repeat;
use function strlen;
use function substr;

use const PREG_OFFSET_CAPTURE;

#[Group('unit')]
final class WriterTest extends TestCase
{
    #[Test]
    public function compressesStreamsWithoutAFilter(): void
    {
        $out    = new MemoryOutput();
        $writer = new Writer($out);
        $writer->writeHeader('1.7');
        $data = str_repeat('php2pdf ', times: 100);
        $writer->add(Stream::of($data));
        $writer->finish(new Dictionary());

        $pdf = $out->contents();
        self::assertStringContainsString('/Filter/FlateDecode', $pdf);
        self::assertStringNotContainsString($data, $pdf);
    }

    #[Test]
    public function leavesPreFilteredStreamsAlone(): void
    {
        $out    = new MemoryOutput();
        $writer = new Writer($out);
        $writer->writeHeader('1.7');
        $writer->add(new Stream(Dictionary::of(['Filter' => new Name('DCTDecode')]), 'JPEGDATA'));
        $writer->finish(new Dictionary());

        self::assertStringContainsString(
            "<</Filter/DCTDecode/Length 8>>\nstream\nJPEGDATA\nendstream",
            $out->contents(),
        );
    }

    #[Test]
    public function refusesDoubleWrites(): void
    {
        $writer = new Writer(new MemoryOutput());
        $ref    = $writer->allocate();
        $writer->write($ref, 1);

        $this->expectException(StateException::class);
        $writer->write($ref, 2);
    }

    #[Test]
    public function refusesUnwrittenAllocations(): void
    {
        $writer = new Writer(new MemoryOutput());
        $writer->writeHeader('1.7');
        $writer->allocate();

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('never written');
        $writer->finish(new Dictionary());
    }

    #[Test]
    public function writesObjectsXrefAndTrailer(): void
    {
        $out    = new MemoryOutput();
        $writer = new Writer($out, compress: false);
        $writer->writeHeader('1.7');

        $first  = $writer->allocate();
        $second = $writer->add(Dictionary::of(['Type' => new Name('Catalog')]));
        $writer->write($first, Stream::of('abc'));
        $writer->finish(Dictionary::of(['Root' => $second]));

        $pdf = $out->contents();
        self::assertStringStartsWith("%PDF-1.7\n", $pdf);
        self::assertStringContainsString("1 0 obj\n<</Length 3>>\nstream\nabc\nendstream\nendobj\n", $pdf);
        self::assertStringContainsString("2 0 obj\n<</Type/Catalog>>\nendobj\n", $pdf);
        self::assertStringEndsWith("%%EOF\n", $pdf);

        self::assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF/', $pdf, $m));
        $xrefOffset = (int) $m[1];
        self::assertSame("xref\n0 3\n", substr($pdf, $xrefOffset, length: 9));

        self::assertSame(1, preg_match(
            '/xref\n0 3\n0000000000 65535 f \n(\d{10}) 00000 n \n(\d{10}) 00000 n \n/',
            $pdf,
            $offsets,
        ));
        self::assertSame('1 0 obj', substr($pdf, (int) $offsets[1], length: 7));
        self::assertSame('2 0 obj', substr($pdf, (int) $offsets[2], length: 7));
        self::assertMatchesRegularExpression('/\/ID\[<[0-9a-f]{32}> <[0-9a-f]{32}>\]/', $pdf);
        self::assertStringContainsString('/Size 3', $pdf);
    }

    #[Test]
    public function writesTheSameStreamTwiceWithoutChangingIt(): void
    {
        $out    = new MemoryOutput();
        $writer = new Writer($out);
        $writer->writeHeader('1.7');
        $data   = str_repeat('php2pdf ', times: 100);
        $stream = Stream::of($data);
        $writer->add($stream);
        $writer->add($stream);
        $writer->finish(new Dictionary());

        self::assertFalse($stream->dictionary->has('Filter'));
        self::assertSame(2, preg_match_all(
            '/<<\/Filter\/FlateDecode\/Length (\d+)>>\nstream\n/',
            $out->contents(),
            $matches,
            PREG_OFFSET_CAPTURE,
        ));
        $bodies = [];
        foreach ($matches[0] as $i => [$header, $offset]) {
            $bodies[] = gzuncompress(substr(
                $out->contents(),
                $offset + strlen($header),
                (int) $matches[1][$i][0],
            ));
        }
        self::assertSame([$data, $data], $bodies);
    }
}
