<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset\Image;

use function bindec;
use function chr;
use function count;
use function intdiv;
use function pack;
use function str_pad;
use function str_split;
use function strlen;

/**
 * JBIG2 files (ITU T.88, Annex D) built in memory from segments, so a test can give a file either
 * organisation, any page layout or a malformed segment, and a page whose picture is known.
 */
final class Jbig2Segments
{
    public const int SYMBOL_DICTIONARY        = 0;
    public const int IMMEDIATE_GENERIC_REGION = 38;
    public const int PAGE_INFORMATION         = 48;
    public const int END_OF_PAGE              = 49;
    public const int END_OF_STRIPE            = 50;
    public const int END_OF_FILE              = 51;

    /**
     * The picture bar() draws, coded with MMR (T.6), padded to whole bytes: the first row a
     * horizontal run of 1 white and 4 black and then V0 to the end; every row under it V0 at each
     * edge and the end; then the end-of-block code. Rows code only against rows with black in them:
     * CoreGraphics misreads a vertical code against the blank line above the first row.
     */
    private const string BAR_BITS = '0010001110111111111111111111111111000000000001000000000001000000';

    /**
     * A generic region holding an 8×8 picture: a black bar down columns 1 to 4, the rest white.
     * Its region information puts it at the page's origin, OR, and its flags choose MMR.
     */
    public static function bar(): string
    {
        $bytes = '';
        foreach (str_split(self::BAR_BITS, length: 8) as $byte) {
            $bytes .= chr((int) bindec($byte));
        }

        return pack('NNNNCC', 8, 8, 0, 0, 0, 1) . $bytes;
    }

    /**
     * The segments of a page showing bar(): page information, the region and the end of the page,
     * then the end of the file.
     *
     * @return array{0: array{0: string, 1: string}, 1: array{0: string, 1: string}, 2: array{0: string, 1: string}, 3: array{0: string, 1: string}}
     */
    public static function barPage(int $page = 1, int $resolution = 0): array
    {
        return [
            self::segment(0, self::PAGE_INFORMATION, $page, self::pageInformation(8, 8, $resolution)),
            self::segment(1, self::IMMEDIATE_GENERIC_REGION, $page, self::bar()),
            self::segment(2, self::END_OF_PAGE, $page, ''),
            self::segment(3, self::END_OF_FILE, 0, ''),
        ];
    }

    /**
     * A sequential file: the JBIG2 signature, then each segment's header followed by its data. A
     * null page count marks the number of pages unknown.
     *
     * @param list<array{0: string, 1: string}> $segments each segment's header and data
     */
    public static function file(array $segments, ?int $pages = 1): string
    {
        $out = self::signature(0x01, $pages);
        foreach ($segments as [$header, $data]) {
            $out .= $header . $data;
        }

        return $out;
    }

    /** Page information: size, resolution in pixels per metre, white, OR, and its stripe flags. */
    public static function pageInformation(int $width, int $height, int $resolution = 0, int $stripes = 0): string
    {
        return pack('NNNNCn', $width, $height, $resolution, $resolution, 0, $stripes);
    }

    /**
     * A random-access file: the JBIG2 signature, then every segment's header, then every
     * segment's data.
     *
     * @param list<array{0: string, 1: string}> $segments each segment's header and data
     */
    public static function randomAccessFile(array $segments, ?int $pages = 1): string
    {
        $out = self::signature(0x00, $pages);
        foreach ($segments as [$header]) {
            $out .= $header;
        }
        foreach ($segments as [, $data]) {
            $out .= $data;
        }

        return $out;
    }

    /**
     * A segment header and its data. The page association takes four bytes past page 255, and the
     * referred-to segments the long form past four.
     *
     * @param list<int> $referred
     *
     * @return array{0: string, 1: string}
     */
    public static function segment(
        int $number,
        int $type,
        int $page,
        string $data,
        array $referred = [],
        ?int $length = null,
    ): array {
        $longPage = $page > 255;
        $count    = count($referred);
        $header   = pack('N', $number) . chr($type | ($longPage ? 0x40 : 0x00));
        $header   .= $count > 4
            ? pack('N', (7 << 29) | $count) . str_pad('', intdiv($count + 8, num2: 8), pad_string: "\x00")
            : chr($count << 5);
        foreach ($referred as $segment) {
            $header .= match (true) {
                $number <= 256 => chr($segment),
                $number <= 65_536 => pack('n', $segment),
                default => pack('N', $segment),
            };
        }
        $header .= $longPage ? pack('N', $page) : chr($page);

        return [$header . pack('N', $length ?? strlen($data)), $data];
    }

    /** The JBIG2 signature and file header: organisation and, when known, the number of pages. */
    private static function signature(int $organisation, ?int $pages): string
    {
        return (
            "\x97JB2\r\n\x1A\n"
                . chr($organisation | (null === $pages ? 0x02 : 0x00))
                . (null === $pages ? '' : pack('N', $pages))
        );
    }
}
