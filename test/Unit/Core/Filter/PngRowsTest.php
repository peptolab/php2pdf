<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core\Filter;

use Php2Pdf\Core\Filter\Flate;
use Php2Pdf\Core\Filter\PngScanlines;
use Php2Pdf\Exception\ParseException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function gzcompress;
use function implode;
use function iterator_to_array;
use function pack;
use function str_repeat;
use function str_split;
use function substr;

/** PNG rows read one at a time from compressed pieces, and compression in pieces. */
#[Group('unit')]
final class PngRowsTest extends TestCase
{
    /** @return list<string> */
    private static function rows(string $compressed, int $height, int $maxBytes = 1_000_000): array
    {
        return iterator_to_array(
            PngScanlines::rows(str_split($compressed, length: 3), 4, $height, 8, 1, $maxBytes),
            preserve_keys: false,
        );
    }

    private static function scanlines(): string
    {
        return (
            pack('C*', 0, 1, 2, 3, 4)
                . pack('C*', 2, 1, 1, 1, 1)
                . pack('C*', 4, 9, 8, 7, 6)
                . pack('C*', 3, 5, 5, 5, 5)
        );
    }

    #[Test]
    public function compressingInPiecesGivesTheBytesOfCompressingWhole(): void
    {
        $data     = str_repeat('pieces of a stream ', times: 500);
        $deflater = Flate::deflater();
        $out      = '';
        foreach (str_split($data, length: 7) as $piece) {
            $out .= Flate::deflate($deflater, $piece);
        }

        self::assertSame(Flate::encode($data), $out . Flate::finish($deflater));
    }

    #[Test]
    public function dataDecodingPastTheLimitIsRefused(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('A compressed stream decodes to more than 8 bytes.');

        self::rows((string) gzcompress(self::scanlines()), 4, maxBytes: 8);
    }

    #[Test]
    public function dataEndingBeforeTheLastRowIsTruncated(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('PNG image data is truncated.');

        self::rows((string) gzcompress(substr(self::scanlines(), offset: 0, length: 12)), 4);
    }

    #[Test]
    public function dataThatIsNotZlibDoesNotInflate(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Flate decompression failed.');

        self::rows('not zlib data', 4);
    }

    #[Test]
    public function rowsAreWhatUnfilteringTheWholeGives(): void
    {
        self::assertSame(
            PngScanlines::unfilter(self::scanlines(), 4, 4, 8, 1),
            implode('', self::rows((string) gzcompress(self::scanlines()), 4)),
        );
    }
}
