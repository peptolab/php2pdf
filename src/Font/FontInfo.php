<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\OpenType\BinaryReader;
use Php2Pdf\Font\OpenType\NameTable;
use Php2Pdf\Font\OpenType\TableDirectory;

use function fclose;
use function fopen;
use function fread;
use function fseek;
use function intdiv;
use function min;
use function pathinfo;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;
use function trim;

use const PATHINFO_FILENAME;
use const PHP_INT_MAX;

/**
 * What the registry knows about a font file without parsing it fully: names and style, read
 * from the name, head and OS/2 tables only.
 *
 * @api
 */
final readonly class FontInfo
{
    public function __construct(
        public string $path,
        public int $faceIndex,
        public string $family,
        public string $subfamily,
        public string $fullName,
        public string $postScriptName,
        public FontStyle $style,
    ) {}

    /**
     * Reads the identifying tables of each face in a file.
     *
     * @return list<self>
     *
     * @throws ParseException
     */
    public static function peek(string $path): array
    {
        $handle = fopen($path, mode: 'rb');
        if (false === $handle) {
            throw new ParseException(sprintf('Cannot open font "%s".', $path));
        }
        try {
            $header = (string) fread($handle, length: 12);
            if (strlen($header) < 12) {
                return [];
            }
            $faces = 'ttcf' === substr($header, offset: 0, length: 4) ? self::collectionFaces($handle) : [0];
            $infos = [];
            foreach ($faces as $index => $base) {
                $info = self::peekFace($handle, $path, $index, $base);
                if (null !== $info) {
                    $infos[] = $info;
                }
            }

            return $infos;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     *
     * @return list<int> offset of each face's table directory
     *
     * @throws ParseException
     */
    private static function collectionFaces(mixed $handle): array
    {
        fseek($handle, offset: 8);
        $header  = new BinaryReader((string) fread($handle, length: 4));
        $count   = min(64, $header->u32(0));
        $offsets = (string) fread($handle, length: 4 * $count);
        $reader  = new BinaryReader($offsets);

        return $reader->u32List(0, intdiv(strlen($offsets), num2: 4));
    }

    /**
     * @param array<int, string> $names
     *
     * @throws ParseException
     */
    private static function fromTables(
        string $path,
        int $index,
        array $names,
        ?BinaryReader $head,
        ?BinaryReader $os2,
    ): self {
        $macStyle = null === $head || $head->length < 46 ? 0 : $head->u16(44);
        $weight   = null === $os2 || $os2->length < 6 ? 400 : $os2->u16(4);
        $fsSel    = null === $os2 || $os2->length < 64 ? 0 : $os2->u16(62);

        $bold   = 0 !== ($macStyle & 1) || $weight >= 600 || 0 !== ($fsSel & 0x20);
        $italic = 0 !== ($macStyle & 2) || 0 !== ($fsSel & 0x01);
        $style  = match (true) {
            $bold && $italic => FontStyle::BoldItalic,
            $bold   => FontStyle::Bold,
            $italic => FontStyle::Italic,
            default => FontStyle::Regular,
        };
        $family =
            $names[NameTable::TYPOGRAPHIC_FAMILY]
                ?? $names[NameTable::FAMILY]
                ?? pathinfo($path, flags: PATHINFO_FILENAME);
        $subfamily = $names[NameTable::TYPOGRAPHIC_SUBFAMILY] ?? $names[NameTable::SUBFAMILY] ?? 'Regular';

        return new self(
            $path,
            $index,
            $family,
            $subfamily,
            $names[NameTable::FULL_NAME] ?? trim("{$family} {$subfamily}"),
            $names[NameTable::POSTSCRIPT] ?? str_replace(' ', replace: '', subject: "{$family}-{$subfamily}"),
            $style,
        );
    }

    /**
     * @param resource $handle
     *
     * @throws ParseException
     */
    private static function peekFace(mixed $handle, string $path, int $index, int $base): ?self
    {
        fseek($handle, $base);
        $directory = (string) fread($handle, length: 12);
        if (strlen($directory) < 12) {
            return null;
        }
        $header    = new BinaryReader($directory);
        $count     = $header->u16(4);
        $directory .= (string) fread($handle, length: 16 * $count);
        $tables    = new TableDirectory(new BinaryReader($directory), dataLength: PHP_INT_MAX);
        $tables    = $tables->tables;

        $read = static function (string $tag) use ($handle, $tables): ?BinaryReader {
            $table = $tables[$tag] ?? null;
            if (null === $table || 0 === $table['length']) {
                return null;
            }
            fseek($handle, $table['offset']);
            $data = (string) fread($handle, $table['length']);

            return strlen($data) === $table['length'] ? new BinaryReader($data) : null;
        };

        $name = $read('name');
        if (null === $name) {
            return null;
        }

        return self::fromTables($path, $index, NameTable::parse($name), $read('head'), $read('OS/2'));
    }
}
