<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use Php2Pdf\Exception\ParseException;

use function array_values;
use function ord;
use function sprintf;
use function strlen;
use function substr;
use function unpack;

/**
 * Big-endian reads from a byte string, as OpenType tables need.
 *
 * @internal
 */
final readonly class BinaryReader
{
    public int $length;

    public function __construct(
        public string $data,
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

        return (
            (ord($this->data[$offset]) << 24)
                | (ord($this->data[$offset + 1]) << 16)
                | (ord($this->data[$offset + 2]) << 8)
                | ord($this->data[$offset + 3])
        );
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
                'Read of %d bytes at offset %d is outside the font data.',
                $length,
                $offset,
            ));
        }
    }
}
