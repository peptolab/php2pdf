<?php

declare(strict_types=1);

namespace PhpPdfTest\Support;

use function array_fill;
use function array_map;
use function chr;
use function count;
use function get_object_vars;
use function gzcompress;
use function implode;
use function ksort;
use function pack;
use function str_pad;
use function str_split;
use function strlen;

/**
 * A baseline TIFF file built in memory: one image in a single strip with the tags the decoder
 * reads, in either byte order, compressed or not, repeated over several pages. Extra tags are
 * tag => [type, values]: SHORT 3 and LONG 4 as integers, RATIONAL 5 as [numerator, denominator]
 * pairs, UNDEFINED 7 as a byte string.
 */
final readonly class TiffFixture
{
    /** @param array<int, array{0: int, 1: list<int>|list<array{0: int, 1: int}>|string}> $tags */
    public function __construct(
        private int $width,
        private int $height,
        private int $photometric,
        private int $bits,
        private int $samples,
        private string $pixels,
        private int $compression = 1,
        private array $tags = [],
        private string $byteOrder = 'II',
        private int $pages = 1,
    ) {}

    /** PackBits with literal runs only, which every decoder reads. */
    private static function packBits(string $data): string
    {
        $out = '';
        foreach (str_split($data, length: 128) as $chunk) {
            $out .= chr(strlen($chunk) - 1) . $chunk;
        }

        return $out;
    }

    private static function padded(string $bytes): string
    {
        return 0 === (strlen($bytes) % 2) ? $bytes : "{$bytes}\0";
    }

    public function bigEndian(): self
    {
        return new self(...[...get_object_vars($this), 'byteOrder' => 'MM']);
    }

    public function bytes(): string
    {
        $strip = match ($this->compression) {
            8       => (string) gzcompress($this->pixels),
            32_773  => self::packBits($this->pixels),
            default => $this->pixels,
        };
        $tags = $this->tags
        + [
            256 => [4, [$this->width]],
            257 => [4, [$this->height]],
            258 => [3, array_fill(0, $this->samples, $this->bits)],
            259 => [3, [$this->compression]],
            262 => [3, [$this->photometric]],
            273 => [4, [0]],
            277 => [3, [$this->samples]],
            278 => [4, [$this->height]],
            279 => [4, [strlen($strip)]],
            284 => [3, [1]],
        ];
        ksort($tags);
        $file = $this->byteOrder . $this->u16(42) . $this->u32(8);
        for ($page = 1; $page <= $this->pages; ++$page) {
            $file .= $this->directory($tags, $strip, strlen($file), $page);
        }

        return $file;
    }

    public function compressed(int $compression): self
    {
        return new self(...[...get_object_vars($this), 'compression' => $compression]);
    }

    public function pages(int $pages): self
    {
        return new self(...[...get_object_vars($this), 'pages' => $pages]);
    }

    /** @param array<int, array{0: int, 1: list<int>|list<array{0: int, 1: int}>|string}> $tags */
    public function withTags(array $tags): self
    {
        return new self(...[...get_object_vars($this), 'tags' => $tags + $this->tags]);
    }

    /**
     * A directory at $at, its out-of-line values and its strip; the next directory, when there is
     * one, follows the strip. Pages are numbered from 1.
     *
     * @param array<int, array{0: int, 1: list<int>|list<array{0: int, 1: int}>|string}> $tags
     */
    private function directory(array $tags, string $strip, int $at, int $page): string
    {
        $values = [];
        foreach ($tags as $tag => [$type, $list]) {
            $values[$tag] = [
                $type,
                7 === $type ? strlen((string) $list) : count((array) $list),
                $this->encode($type, $list),
            ];
        }
        $extraAt = $at + 2 + (count($values) * 12) + 4;
        $extra   = '';
        foreach ($values as [, , $bytes]) {
            $extra .= strlen($bytes) > 4 ? self::padded($bytes) : '';
        }
        $stripAt = $extraAt + strlen($extra);
        $extra   = '';
        $entries = '';
        foreach ($values as $tag => [$type, $count, $bytes]) {
            $bytes = 273 === $tag ? $this->u32($stripAt) : $bytes;
            $value = str_pad($bytes, length: 4, pad_string: "\0");
            if (strlen($bytes) > 4) {
                $value = $this->u32($extraAt + strlen($extra));
                $extra .= self::padded($bytes);
            }
            $entries .= $this->u16($tag) . $this->u16($type) . $this->u32($count) . $value;
        }
        $next = $page < $this->pages ? $stripAt + strlen(self::padded($strip)) : 0;

        return $this->u16(count($values)) . $entries . $this->u32($next) . $extra . self::padded($strip);
    }

    /** @param list<int>|list<array{0: int, 1: int}>|string $list */
    private function encode(int $type, array|string $list): string
    {
        if (7 === $type) {
            return (string) $list;
        }
        $pack = match ($type) {
            3       => fn(mixed $v): string => $this->u16((int) $v),
            5       => fn(mixed $v): string => $this->u32((int) ((array) $v)[0]) . $this->u32((int) ((array) $v)[1]),
            default => fn(mixed $v): string => $this->u32((int) $v),
        };

        return implode('', array_map($pack, (array) $list));
    }

    private function u16(int $value): string
    {
        return pack('II' === $this->byteOrder ? 'v' : 'n', $value);
    }

    private function u32(int $value): string
    {
        return pack('II' === $this->byteOrder ? 'V' : 'N', $value);
    }
}
