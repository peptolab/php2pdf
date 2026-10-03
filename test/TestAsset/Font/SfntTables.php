<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset\Font;

use function count;
use function ksort;
use function pack;
use function str_repeat;
use function strlen;
use function substr;
use function unpack;

/**
 * Rewrites a single-face sfnt font with some of its tables replaced, added or removed, so a test
 * can give a real font one malformed or missing table. Checksums are left zero: the parser does
 * not verify them.
 */
final class SfntTables
{
    /**
     * @param array<string, string|null> $changes table tag => new bytes, or null to drop the table
     */
    public static function replace(string $font, array $changes): string
    {
        $tables = self::tables($font);
        foreach ($changes as $tag => $data) {
            if (null === $data) {
                unset($tables[$tag]);
                continue;
            }
            $tables[$tag] = $data;
        }
        ksort($tables);

        return self::assemble(substr($font, offset: 0, length: 4), $tables);
    }

    /** @param array<string, string> $tables */
    private static function assemble(string $version, array $tables): string
    {
        $count    = count($tables);
        $power    = 1;
        $selector = 0;
        while (($power * 2) <= $count) {
            $power *= 2;
            ++$selector;
        }
        $directory = $version . pack('nnnn', $count, $power * 16, $selector, ($count - $power) * 16);
        $body      = '';
        $offset    = 12 + (16 * $count);
        foreach ($tables as $tag => $data) {
            $directory .= pack('a4NNN', $tag, 0, $offset + strlen($body), strlen($data));
            $body      .= $data . str_repeat("\0", (4 - (strlen($data) % 4)) % 4);
        }

        return $directory . $body;
    }

    /** @return array<string, string> */
    private static function tables(string $font): array
    {
        /** @var array{1: int} $header */
        $header = unpack('n', $font, offset: 4);
        $tables = [];
        for ($i = 0; $i < $header[1]; ++$i) {
            /** @var array{tag: string, offset: int, length: int} $record */
            $record                 = unpack('a4tag/Nsum/Noffset/Nlength', $font, offset: 12 + (16 * $i));
            $tables[$record['tag']] = substr($font, $record['offset'], $record['length']);
        }

        return $tables;
    }
}
