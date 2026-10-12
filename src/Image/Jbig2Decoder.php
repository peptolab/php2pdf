<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Override;
use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\ParseException;

use function intdiv;
use function max;
use function ord;
use function pack;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * Reads a JBIG2 file (ITU T.88, Annex D) for one of its pages, which is passed through to PDF
 * without decoding (JBIG2Decode). The page's segments become the image data, renumbered as page
 * 1 as PDF expects, and the segments shared by every page (symbol dictionaries, say) become its
 * JBIG2Globals. The file header and the end-of-page and end-of-file segments are left out, as
 * PDF requires; the size and resolution come from the page information segment.
 *
 * @internal
 */
final readonly class Jbig2Decoder implements ImageDecoderInterface
{
    public const string SIGNATURE = "\x97JB2\r\n\x1A\n";

    private const int PAGE_INFORMATION = 48;
    private const int END_OF_PAGE      = 49;
    private const int END_OF_STRIPE    = 50;
    private const int END_OF_FILE      = 51;

    /** A segment data length that is only known by decoding (an immediate generic region's). */
    private const int UNKNOWN_LENGTH = 0xFFFF_FFFF;

    /** A page height a striped page leaves to its end-of-stripe segments. */
    private const int UNKNOWN_HEIGHT = 0xFFFF_FFFF;

    /**
     * A segment header: its type, page and bytes, and where its page association field is.
     *
     * @return array{type: int, page: int, length: int, header: string, pageAt: int, pageSize: int, end: int}
     *
     * @throws ParseException
     */
    private static function header(BinaryReader $r, int $at): array
    {
        $number   = $r->u32($at);
        $flags    = ord($r->bytes($at + 4, length: 1));
        $count    = ord($r->bytes($at + 5, length: 1)) >> 5;
        $referred = $at + 6;
        if (7 === $count) {
            $count    = $r->u32($at + 5) & 0x1FFF_FFFF;
            $referred = $at + 9 + intdiv($count + 8, num2: 8);
        }
        $size = match (true) {
            $number <= 256 => 1,
            $number <= 65_536 => 2,
            default => 4,
        };
        $pageAt   = $referred + ($count * $size);
        $pageSize = 0 === ($flags & 0x40) ? 1 : 4;
        $page     = 1 === $pageSize ? ord($r->bytes($pageAt, length: 1)) : $r->u32($pageAt);
        $end      = $pageAt + $pageSize + 4;
        $length   = $r->u32($pageAt + $pageSize);
        if (self::UNKNOWN_LENGTH === $length) {
            throw new ParseException('JBIG2 segments of unknown length are not supported.');
        }

        return [
            'type'     => $flags & 0x3F,
            'page'     => $page,
            'length'   => $length,
            'header'   => $r->bytes($at, $end - $at),
            'pageAt'   => $pageAt - $at,
            'pageSize' => $pageSize,
            'end'      => $end,
        ];
    }

    /** A segment header with its page association made 1, the page PDF's JBIG2 data is on. */
    private static function onPageOne(string $header, int $pageAt, int $pageSize): string
    {
        return (
            substr($header, offset: 0, length: $pageAt)
                . (1 === $pageSize ? "\x01" : pack('N', 1))
                . substr($header, $pageAt + $pageSize)
        );
    }

    /**
     * The parts of a JBIG2 file PDF takes for one page: the segments shared by every page, the
     * page's own segments on page 1, its page information, and the last row its stripes end at.
     *
     * @param list<array{type: int, page: int, length: int, header: string, pageAt: int, pageSize: int, end: int, data: string}> $segments
     *
     * @return array{0: string, 1: string, 2: BinaryReader|null, 3: int}
     *
     * @throws ParseException
     */
    private static function page(array $segments, int $page): array
    {
        $globals   = '';
        $body      = '';
        $info      = null;
        $stripeEnd = -1;
        foreach ($segments as $segment) {
            $type = $segment['type'];
            if (self::END_OF_PAGE === $type || self::END_OF_FILE === $type) {
                continue;
            }
            if (0 === $segment['page']) {
                $globals .= $segment['header'] . $segment['data'];
                continue;
            }
            if ($page !== $segment['page']) {
                continue;
            }
            if (self::PAGE_INFORMATION === $type) {
                $info = new BinaryReader($segment['data'], what: 'the JBIG2 page information');
            }
            if (self::END_OF_STRIPE === $type) {
                $stripeEnd = max($stripeEnd, (new BinaryReader($segment['data'], what: 'a JBIG2 stripe'))->u32(0));
            }
            $body .= self::onPageOne($segment['header'], $segment['pageAt'], $segment['pageSize']) . $segment['data'];
        }

        return [$globals, $body, $info, $stripeEnd];
    }

    /**
     * Every segment of a random-access file: every header first, up to the end-of-file segment,
     * and then every segment's data in the same order.
     *
     * @return list<array{type: int, page: int, length: int, header: string, pageAt: int, pageSize: int, end: int, data: string}>
     *
     * @throws ParseException
     */
    private static function randomAccess(BinaryReader $r, int $at): array
    {
        $headers = [];
        while ($at < $r->length) {
            $segment   = self::header($r, $at);
            $headers[] = $segment;
            $at        = $segment['end'];
            if (self::END_OF_FILE === $segment['type']) {
                break;
            }
        }
        $out = [];
        foreach ($headers as $segment) {
            $segment['data'] = $r->bytes($at, $segment['length']);
            $out[]           = $segment;
            $at              += $segment['length'];
        }

        return $out;
    }

    /**
     * Every segment of a sequential file: each header followed by its data.
     *
     * @return list<array{type: int, page: int, length: int, header: string, pageAt: int, pageSize: int, end: int, data: string}>
     *
     * @throws ParseException
     */
    private static function sequential(BinaryReader $r, int $at): array
    {
        $out = [];
        while ($at < $r->length) {
            $segment         = self::header($r, $at);
            $segment['data'] = $r->bytes($segment['end'], $segment['length']);
            $out[]           = $segment;
            $at              = $segment['end'] + $segment['length'];
            if (self::END_OF_FILE === $segment['type']) {
                break;
            }
        }

        return $out;
    }

    #[Override]
    public function accepts(string $data): bool
    {
        return str_starts_with($data, self::SIGNATURE);
    }

    /** @throws ParseException for a malformed file, a missing page or an image over the limits */
    #[Override]
    public function decode(string $data, Limits $limits, ImageOptions $options): ImageData
    {
        $r     = new BinaryReader($data, what: 'the JBIG2 data');
        $flags = ord($r->bytes(8, length: 1));
        // The number of pages follows the flags unless the file says it is unknown.
        $start    = 0 === ($flags & 0x02) ? 13 : 9;
        $segments = 1 === ($flags & 0x01) ? self::sequential($r, $start) : self::randomAccess($r, $start);
        [$globals, $body, $info, $stripeEnd] = self::page($segments, $options->page);
        if (null === $info) {
            throw new ParseException(sprintf('JBIG2 file has no page %d.', $options->page));
        }
        $width  = $info->u32(0);
        $height = $info->u32(4);
        if (self::UNKNOWN_HEIGHT === $height) {
            $height = $stripeEnd + 1;
        }
        if ($width < 1 || $height < 1) {
            throw new ParseException('JBIG2 page has no pixels.');
        }
        $limits->assertImagePixels($width, $height);
        $dpiX = $info->u32(8) * 0.0254;
        $dpiY = $info->u32(12) * 0.0254;

        return new ImageData(
            new Raster(
                $width,
                $height,
                1,
                ImageColorSpace::gray(),
                $body,
                'JBIG2Decode',
                jbig2Globals: '' === $globals ? null : $globals,
            ),
            dpiX: $dpiX > 0.0 ? $dpiX : 72.0,
            dpiY: $dpiY > 0.0 ? $dpiY : 72.0,
        );
    }

    #[Override]
    public function hasPages(): bool
    {
        return true;
    }

    #[Override]
    public function name(): string
    {
        return 'JBIG2';
    }
}
