<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

use Php2Pdf\Exception\ParseException;

use function array_values;
use function ord;
use function sprintf;
use function strlen;
use function substr;
use function ucfirst;
use function unpack;

/**
 * Reads big-endian integers, fixed-point and floating-point numbers from a byte string, as
 * OpenType, JPEG 2000 and swatch files store them. A read past the end is an error that says what
 * was cut short.
 *
 * @internal
 */
final readonly class BinaryReader
{
    public int $length;

    /** @param string $what the data, as an error names it: "the font data", "the swatch file" */
    public function __construct(
        public string $data,
        private string $what = 'the data',
    ) {
        $this->length = strlen($data);
    }

    /** @throws ParseException */
    public function bytes(int $offset, int $length): string
    {
        $this->check($offset, $length);

        return substr($this->data, $offset, $length);
    }

    /**
     * A 32-bit IEEE floating-point number.
     *
     * @throws ParseException
     */
    public function f32(int $offset): float
    {
        $this->check($offset, 4);
        /** @var array{1: float} $value */
        $value = unpack('G', $this->data, $offset);

        return $value[1];
    }

    /**
     * A 16.16 fixed-point number.
     *
     * @throws ParseException
     */
    public function fixed(int $offset): float
    {
        return $this->i32($offset) / 65_536.0;
    }

    /** @throws ParseException */
    public function i16(int $offset): int
    {
        $v = $this->u16($offset);

        return $v >= 0x8000 ? $v - 0x1_0000 : $v;
    }

    /** @throws ParseException */
    public function i32(int $offset): int
    {
        $v = $this->u32($offset);

        return $v >= 0x8000_0000 ? $v - 0x1_0000_0000 : $v;
    }

    /** @throws ParseException */
    public function tag(int $offset): string
    {
        return $this->bytes($offset, 4);
    }

    /** @throws ParseException */
    public function u16(int $offset): int
    {
        $this->check($offset, 2);

        return (ord($this->data[$offset]) << 8) | ord($this->data[$offset + 1]);
    }

    /**
     * Reads $count consecutive unsigned 16-bit values.
     *
     * @return list<int>
     *
     * @throws ParseException
     */
    public function u16List(int $offset, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $this->check($offset, $count * 2);
        /** @var list<int> */
        return array_values(unpack("n{$count}", $this->data, $offset));
    }

    /** @throws ParseException */
    public function u32(int $offset): int
    {
        $this->check($offset, 4);
        /** @var array{1: int} $value */
        $value = unpack('N', $this->data, $offset);

        return $value[1];
    }

    /**
     * Reads $count consecutive unsigned 32-bit values.
     *
     * @return list<int>
     *
     * @throws ParseException
     */
    public function u32List(int $offset, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $this->check($offset, $count * 4);
        /** @var list<int> */
        return array_values(unpack("N{$count}", $this->data, $offset));
    }

    /** @throws ParseException */
    public function u8(int $offset): int
    {
        $this->check($offset, 1);

        return ord($this->data[$offset]);
    }

    /** @throws ParseException */
    private function check(int $offset, int $length): void
    {
        if ($offset < 0 || ($offset + $length) > $this->length) {
            throw new ParseException(sprintf(
                '%s is cut short: it has no %d bytes at offset %d.',
                ucfirst($this->what),
                $length,
                $offset,
            ));
        }
    }
}
