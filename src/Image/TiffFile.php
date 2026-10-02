<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Php2Pdf\Exception\ParseException;

use function array_key_exists;
use function count;
use function ord;
use function str_starts_with;
use function strlen;
use function substr;
use function unpack;

/**
 * The structure of a TIFF file (or of the TIFF block in an EXIF segment): its byte order, its
 * chain of image directories and their tag values. Values out of range read as absent.
 *
 * @internal
 */
final class TiffFile
{
    private const int MAX_DIRECTORIES = 4096;

    /** Bytes per value by field type. */
    private const array TYPE_SIZES = [
        1  => 1,
        2  => 1,
        3  => 2,
        4  => 4,
        5  => 8,
        6  => 1,
        7  => 1,
        8  => 2,
        9  => 4,
        10 => 8,
        11 => 4,
        12 => 8,
    ];

    private readonly bool $littleEndian;

    /** @var list<array<int, array{0: int, 1: int, 2: int}>>|null tag => type, count and value offset, per directory */
    private ?array $directories = null;

    public function __construct(
        private readonly string $data,
    ) {
        $this->littleEndian = str_starts_with($data, 'II');
    }

    public static function isTiff(string $data): bool
    {
        return str_starts_with($data, "II*\0") || str_starts_with($data, "MM\0*");
    }

    /** The bytes a strip or other block occupies, or null when it lies outside the file. */
    public function bytes(int $offset, int $length): ?string
    {
        if ($offset < 0 || $length < 0 || ($offset + $length) > strlen($this->data)) {
            return null;
        }

        return substr($this->data, $offset, $length);
    }

    /** @throws ParseException */
    public function directoryCount(): int
    {
        return count($this->directories());
    }

    /** @throws ParseException */
    public function has(int $directory, int $tag): bool
    {
        return array_key_exists($tag, $this->directories()[$directory] ?? []);
    }

    public function isLittleEndian(): bool
    {
        return $this->littleEndian;
    }

    /**
     * The raw bytes of a tag (an ICC profile, a colour map), or null.
     *
     * @throws ParseException
     */
    public function raw(int $directory, int $tag): ?string
    {
        $entry = $this->directories()[$directory][$tag] ?? null;
        if (null === $entry) {
            return null;
        }
        [$type, $count, $at] = $entry;

        return $this->bytes($at, $count * (self::TYPE_SIZES[$type] ?? 1));
    }

    /**
     * A tag's first value, or $default when it is absent.
     *
     * @throws ParseException
     */
    public function value(int $directory, int $tag, int|float|null $default = null): int|float|null
    {
        return $this->values($directory, $tag)[0] ?? $default;
    }

    /**
     * A tag's numeric values (BYTE, SHORT, LONG and RATIONAL), or an empty list.
     *
     * @return list<int|float>
     *
     * @throws ParseException
     */
    public function values(int $directory, int $tag): array
    {
        $entry = $this->directories()[$directory][$tag] ?? null;
        if (null === $entry) {
            return [];
        }
        [$type, $count, $at] = $entry;
        $values = [];
        for ($i = 0; $i < $count; ++$i) {
            $value = match ($type) {
                1, 7    => $this->u8($at + $i),
                3       => $this->u16($at + ($i * 2)),
                4       => $this->u32($at + ($i * 4)),
                5       => $this->rational($at + ($i * 8)),
                default => null,
            };
            if (null === $value) {
                return $values;
            }
            $values[] = $value;
        }

        return $values;
    }

    /**
     * @return list<array<int, array{0: int, 1: int, 2: int}>>
     *
     * @throws ParseException
     */
    private function directories(): array
    {
        if (null !== $this->directories) {
            return $this->directories;
        }
        $directories = [];
        $seen        = [];
        $offset      = $this->u32(4);
        while (
            null !== $offset
            && $offset > 0
            && ! array_key_exists($offset, $seen)
            && count($directories) < self::MAX_DIRECTORIES
        ) {
            $seen[$offset] = true;
            $count         = $this->u16($offset);
            if (null === $count) {
                break;
            }
            $entries = [];
            for ($i = 0; $i < $count; ++$i) {
                $entry = $offset + 2 + ($i * 12);
                $tag   = $this->u16($entry);
                $type  = $this->u16($entry + 2);
                $n     = $this->u32($entry + 4);
                if (null === $tag || null === $type || null === $n) {
                    break;
                }
                $size          = (self::TYPE_SIZES[$type] ?? 1) * $n;
                $at            = $size <= 4 ? $entry + 8 : $this->u32($entry + 8) ?? -1;
                $entries[$tag] = [$type, $n, $at];
            }
            $directories[] = $entries;
            $offset        = $this->u32($offset + 2 + ($count * 12));
        }
        if ([] === $directories) {
            throw new ParseException('TIFF file has no image directory.');
        }

        return $this->directories = $directories;
    }

    private function rational(int $at): ?float
    {
        $numerator   = $this->u32($at);
        $denominator = $this->u32($at + 4);

        return null === $numerator || null === $denominator || 0 === $denominator ? null : $numerator / $denominator;
    }

    private function u16(int $at): ?int
    {
        $bytes = $this->bytes($at, 2);
        $value = null === $bytes ? false : unpack($this->littleEndian ? 'v' : 'n', $bytes);

        return false === $value ? null : (int) $value[1];
    }

    private function u32(int $at): ?int
    {
        $bytes = $this->bytes($at, 4);
        $value = null === $bytes ? false : unpack($this->littleEndian ? 'V' : 'N', $bytes);

        return false === $value ? null : (int) $value[1];
    }

    private function u8(int $at): ?int
    {
        $bytes = $this->bytes($at, 1);

        return null === $bytes ? null : ord($bytes);
    }
}
