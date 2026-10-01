<?php

declare(strict_types=1);

namespace PhpPdf\Font\OpenType;

use PhpPdf\Exception\ParseException;

use function array_key_exists;
use function sprintf;

/**
 * The sfnt table directory. Handles TrueType, CFF-flavoured OpenType and the faces of a
 * TrueType collection.
 *
 * @internal
 */
final readonly class TableDirectory
{
    /** @var array<string, array{offset: int, length: int}> */
    public array $tables;

    public bool $isCff;

    /**
     * @param int|null $dataLength the length of the whole font, when $reader holds only the directory
     */
    public function __construct(BinaryReader $reader, int $faceIndex = 0, ?int $dataLength = null)
    {
        $dataLength ??= $reader->length;
        $base       = 0;
        $tag        = $reader->tag(0);
        if ('ttcf' === $tag) {
            $count = $reader->u32(8);
            if ($faceIndex < 0 || $faceIndex >= $count) {
                throw new ParseException(sprintf(
                    'Font collection has %d faces; face %d requested.',
                    $count,
                    $faceIndex,
                ));
            }
            $base = $reader->u32(12 + (4 * $faceIndex));
            $tag  = $reader->tag($base);
        }
        if ("\x00\x01\x00\x00" !== $tag && 'true' !== $tag && 'OTTO' !== $tag) {
            throw new ParseException('Not an OpenType or TrueType font.');
        }

        $count  = $reader->u16($base + 4);
        $tables = [];
        for ($i = 0; $i < $count; ++$i) {
            $record = $base + 12 + (16 * $i);
            $name   = $reader->tag($record);
            $offset = $reader->u32($record + 8);
            $length = $reader->u32($record + 12);
            if (($offset + $length) > $dataLength) {
                throw new ParseException(sprintf('Table "%s" lies outside the font data.', $name));
            }
            $tables[$name] = ['offset' => $offset, 'length' => $length];
        }
        $this->tables = $tables;
        $this->isCff  = array_key_exists('CFF ', $tables) || array_key_exists('CFF2', $tables);
    }

    /** @return array{offset: int, length: int} */
    public function get(string $tag): array
    {
        return $this->tables[$tag] ?? throw new ParseException(sprintf('Font has no "%s" table.', $tag));
    }

    public function has(string $tag): bool
    {
        return array_key_exists($tag, $this->tables);
    }

    public function slice(BinaryReader $reader, string $tag): BinaryReader
    {
        $table = $this->get($tag);

        return new BinaryReader($reader->bytes($table['offset'], $table['length']));
    }
}
