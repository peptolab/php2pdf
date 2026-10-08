<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset\Font;

use function count;
use function pack;
use function strlen;
use function substr;
use function unpack;

/**
 * Packs single-face sfnt fonts into a TrueType collection (ttcf, version 1.0). Each face keeps its
 * own copy of its tables, with the table offsets moved to where the face lands in the collection.
 */
final class FontCollectionBuilder
{
    /** @param list<string> $fonts */
    public static function collection(array $fonts): string
    {
        $offset  = 12 + (4 * count($fonts));
        $offsets = [];
        $body    = '';
        foreach ($fonts as $font) {
            $offsets[] = $offset + strlen($body);
            $body      .= self::relocate($font, $offset + strlen($body));
        }

        return 'ttcf' . pack('nnN', 1, 0, count($fonts)) . pack('N*', ...$offsets) . $body;
    }

    private static function relocate(string $font, int $base): string
    {
        /** @var array{1: int} $header */
        $header    = unpack('n', $font, offset: 4);
        $directory = substr($font, offset: 0, length: 12);
        for ($i = 0; $i < $header[1]; ++$i) {
            /** @var array{tag: string, sum: int, offset: int, length: int} $record */
            $record    = unpack('a4tag/Nsum/Noffset/Nlength', $font, offset: 12 + (16 * $i));
            $directory .= pack('a4NNN', $record['tag'], $record['sum'], $record['offset'] + $base, $record['length']);
        }

        return $directory . substr($font, strlen($directory));
    }
}
