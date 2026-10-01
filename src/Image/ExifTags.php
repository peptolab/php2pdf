<?php

declare(strict_types=1);

namespace PhpPdf\Image;

use function strlen;
use function substr;
use function unpack;

/**
 * The SHORT and RATIONAL values of a TIFF block's first image directory, read in its byte order.
 * Anything malformed or out of range reads as absent.
 *
 * @internal
 */
final class ExifTags
{
    private const int TYPE_SHORT    = 3;
    private const int TYPE_RATIONAL = 5;

    private readonly bool $littleEndian;

    /** @var array<int, int|float|null>|null tag => value, read on first use */
    private ?array $values = null;

    public function __construct(
        private readonly string $data,
    ) {
        $this->littleEndian = 'II' === substr($data, offset: 0, length: 2);
    }

    public function value(int $tag): int|float|null
    {
        $this->values ??= $this->read();

        return $this->values[$tag] ?? null;
    }

    private function rational(?int $at): ?float
    {
        $numerator   = null === $at ? null : $this->u32($at);
        $denominator = null === $at ? null : $this->u32($at + 4);
        if (null === $numerator || null === $denominator || 0 === $denominator) {
            return null;
        }

        return $numerator / $denominator;
    }

    /** @return array<int, int|float|null> */
    private function read(): array
    {
        $order = substr($this->data, offset: 0, length: 2);
        $ifd   = 'II' === $order || 'MM' === $order ? $this->u32(4) : null;
        $count = null === $ifd ? null : $this->u16($ifd);
        if (null === $ifd || null === $count) {
            return [];
        }
        $values = [];
        for ($i = 0; $i < $count; ++$i) {
            $entry = $ifd + 2 + ($i * 12);
            $tag   = $this->u16($entry);
            if (null === $tag) {
                break;
            }
            $values[$tag] = match ($this->u16($entry + 2)) {
                self::TYPE_SHORT => $this->u16($entry + 8),
                self::TYPE_RATIONAL => $this->rational($this->u32($entry + 8)),
                default => null,
            };
        }

        return $values;
    }

    private function u16(int $at): ?int
    {
        if ($at < 0 || ($at + 2) > strlen($this->data)) {
            return null;
        }
        $value = unpack($this->littleEndian ? 'v' : 'n', $this->data, $at);

        return false === $value ? null : (int) $value[1];
    }

    private function u32(int $at): ?int
    {
        if ($at < 0 || ($at + 4) > strlen($this->data)) {
            return null;
        }
        $value = unpack($this->littleEndian ? 'V' : 'N', $this->data, $at);

        return false === $value ? null : (int) $value[1];
    }
}
