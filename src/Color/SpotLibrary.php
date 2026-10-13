<?php

declare(strict_types=1);

namespace Php2Pdf\Color;

use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Core\FileReader;
use Php2Pdf\Core\LineEnd;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_search;
use function array_values;
use function count;
use function is_numeric;
use function is_string;
use function mb_convert_encoding;
use function preg_replace;
use function preg_split;
use function rtrim;
use function sort;
use function sprintf;
use function str_getcsv;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * Named spot colours from swatch data of your own, such as a colour book exported from InDesign
 * or Illustrator: an Adobe Swatch Exchange (.ase) file, whose spot swatches are read, or a CSV
 * table. No colour book data is bundled; Pantone and other books are licensed by their owners.
 *
 * Names are matched exactly, then ignoring case and runs of spaces, so "Pantone 300 c" finds
 * "PANTONE 300 C".
 *
 * @api
 */
final class SpotLibrary
{
    private const array CSV_MODELS = [
        'cmyk' => ['c', 'm', 'y', 'k'],
        'lab'  => ['l', 'a', 'b'],
        'rgb'  => ['r', 'g', 'b'],
    ];

    /** @var array<string, Gray|Rgb|Cmyk|Lab> alternates by spot name */
    private array $colours = [];

    /** @var array<string, string> loose key => name as defined */
    private array $keys = [];

    /** @param iterable<array-key, Gray|Rgb|Cmyk|Lab> $colours alternates by spot name: a numeric name is an integer key */
    public function __construct(iterable $colours = [])
    {
        foreach ($colours as $name => $colour) {
            $name                              = (string) $name;
            $this->colours["={$name}"]         = $colour;
            $this->keys[self::looseKey($name)] ??= $name;
        }
    }

    /**
     * Spot swatches from Adobe Swatch Exchange data; process and global swatches are skipped.
     * Lab swatches keep their Lab values.
     *
     * @throws ParseException when the data is not a well-formed ASE file
     */
    public static function fromAseData(string $data): self
    {
        if (strlen($data) < 12 || 'ASEF' !== substr($data, offset: 0, length: 4)) {
            throw new ParseException('Not an Adobe Swatch Exchange file.');
        }
        $file    = new BinaryReader($data, what: 'the swatch file');
        $blocks  = $file->u32(8);
        $offset  = 12;
        $library = new self();
        for ($k = 0; $k < $blocks; ++$k) {
            $type   = $file->u16($offset);
            $length = $file->u32($offset + 2);
            $body   = substr($data, $offset + 6, $length);
            if (strlen($body) !== $length) {
                throw new ParseException('The swatch file ends inside a block.');
            }
            $offset += 6 + $length;
            if (0x0001 !== $type) {
                continue;
            }
            [$name, $colour, $kind] = self::aseColour($body);
            if (1 === $kind) {
                $library = $library->with($name, $colour);
            }
        }

        return $library;
    }

    /**
     * Spot colours from a CSV table with a header row: a name column and the alternate as
     * c, m, y, k in percent, as l, a, b, or as r, g, b from 0 to 255.
     *
     * @throws ParseException when the header names no known colour columns or a cell is not a number
     */
    public static function fromCsvData(string $data): self
    {
        $lines  = preg_split('/' . LineEnd::PATTERN . '/', trim($data));
        $lines  = false === $lines ? [] : $lines;
        $header = array_values(array_map(
            static fn(?string $cell): string => strtolower(trim((string) $cell)),
            str_getcsv($lines[0] ?? '', escape: ''),
        ));
        [$model, $columns] = self::csvModel($header);
        $nameColumn = (int) array_search('name', $header, strict: true);
        $library    = new self();
        foreach ($lines as $row => $line) {
            if (0 === $row || '' === trim($line)) {
                continue;
            }
            $cells = array_map(
                static fn(mixed $cell): string => is_string($cell) ? $cell : '',
                str_getcsv($line, escape: ''),
            );
            $name = trim($cells[$nameColumn] ?? '');
            if ('' === $name) {
                throw new ParseException(sprintf('Row %d of the swatch table has no name.', $row + 1));
            }
            $library = $library->with($name, self::csvColour($model, self::csvNumbers($cells, $columns, $row + 1)));
        }

        return $library;
    }

    /** @throws IoException|ParseException */
    public static function openAse(string $path): self
    {
        return self::fromAseData(self::read($path));
    }

    /** @throws IoException|ParseException */
    public static function openCsv(string $path): self
    {
        return self::fromCsvData(self::read($path));
    }

    /**
     * Name, alternate and swatch kind (0 global, 1 spot, 2 process) of an ASE colour block.
     *
     * @return array{0: string, 1: Gray|Rgb|Cmyk|Lab, 2: int}
     *
     * @throws ParseException
     */
    private static function aseColour(string $body): array
    {
        $swatch = new BinaryReader($body, what: 'a swatch');
        $chars  = $swatch->u16(0);
        $name   = rtrim(
            (string) mb_convert_encoding(
                substr($body, offset: 2, length: $chars * 2),
                to_encoding: 'UTF-8',
                from_encoding: 'UTF-16BE',
            ),
            characters: "\0",
        );
        $at    = 2 + ($chars * 2);
        $model = substr($body, $at, length: 4);
        $count = match ($model) {
            'CMYK'         => 4,
            'RGB ', 'LAB ' => 3,
            'Gray'         => 1,
            default        => throw new ParseException(sprintf(
                'Swatch "%s" has an unknown colour model "%s".',
                $name,
                $model,
            )),
        };
        $v      = /** @throws ParseException */ static fn(int $i): float => $swatch->f32($at + 4 + ($i * 4));
        $colour = match ($model) {
            'CMYK'  => new Cmyk($v(0), $v(1), $v(2), $v(3)),
            'RGB '  => new Rgb($v(0), $v(1), $v(2)),
            'LAB '  => new Lab($v(0) * 100.0, $v(1), $v(2)),
            default => new Gray($v(0)),
        };

        return [$name, $colour, $swatch->u16($at + 4 + ($count * 4))];
    }

    /**
     * @param 'cmyk'|'lab'|'rgb' $model
     * @param list<float>        $v
     */
    private static function csvColour(string $model, array $v): Rgb|Cmyk|Lab
    {
        return match ($model) {
            'cmyk'  => Cmyk::fromPercent($v[0] ?? 0.0, $v[1] ?? 0.0, $v[2] ?? 0.0, $v[3] ?? 0.0),
            'lab'   => new Lab($v[0] ?? 0.0, $v[1] ?? 0.0, $v[2] ?? 0.0),
            default => new Rgb(($v[0] ?? 0.0) / 255.0, ($v[1] ?? 0.0) / 255.0, ($v[2] ?? 0.0) / 255.0),
        };
    }

    /**
     * The colour model a CSV header names and the columns holding its values.
     *
     * @param list<string> $header
     *
     * @return array{0: 'cmyk'|'lab'|'rgb', 1: list<int>}
     *
     * @throws ParseException
     */
    private static function csvModel(array $header): array
    {
        if (false === array_search('name', $header, strict: true)) {
            throw new ParseException('The swatch table needs a "name" column.');
        }
        foreach (self::CSV_MODELS as $model => $names) {
            $columns = [];
            foreach ($names as $column) {
                $index = array_search($column, $header, strict: true);
                if (false === $index) {
                    continue 2;
                }
                $columns[] = $index;
            }

            return [$model, $columns];
        }

        throw new ParseException('The swatch table needs c, m, y, k or l, a, b or r, g, b columns.');
    }

    /**
     * @param array<array-key, string> $cells
     * @param list<int>           $columns
     *
     * @return list<float>
     *
     * @throws ParseException
     */
    private static function csvNumbers(array $cells, array $columns, int $row): array
    {
        $values = [];
        foreach ($columns as $column) {
            $value = trim($cells[$column] ?? '');
            if (! is_numeric($value)) {
                throw new ParseException(sprintf(
                    'Row %d of the swatch table has "%s" where a number belongs.',
                    $row,
                    $value,
                ));
            }
            $values[] = (float) $value;
        }

        return $values;
    }

    private static function looseKey(string $name): string
    {
        return strtolower((string) preg_replace('/\s+/', replacement: ' ', subject: trim($name)));
    }

    /** @throws IoException */
    private static function read(string $path): string
    {
        return FileReader::read($path, 'swatch file');
    }

    public function count(): int
    {
        return count($this->colours);
    }

    /**
     * The spot colour of a name, at a tint.
     *
     * @throws InvalidArgumentException when the library has no such colour
     */
    public function get(string $name, float $tint = 1.0): Spot
    {
        $defined = $this->find($name);
        $colour  = null === $defined ? null : $this->colours["={$defined}"] ?? null;
        if (null === $defined || null === $colour) {
            throw new InvalidArgumentException(sprintf('The swatch library has no colour named "%s".', $name));
        }

        return new Spot($defined, $colour, $tint);
    }

    public function has(string $name): bool
    {
        return null !== $this->find($name);
    }

    /**
     * The colours' names as defined, sorted.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_map(static fn(string $key): string => substr($key, offset: 1), array_keys($this->colours));
        sort($names);

        return $names;
    }

    /** A library with one more colour, or another alternate for a name it has. */
    public function with(string $name, Gray|Rgb|Cmyk|Lab $alternate): self
    {
        $library                              = clone $this;
        $library->colours["={$name}"]         = $alternate;
        $library->keys[self::looseKey($name)] ??= $name;

        return $library;
    }

    private function find(string $name): ?string
    {
        if (array_key_exists("={$name}", $this->colours)) {
            return $name;
        }

        return $this->keys[self::looseKey($name)] ?? null;
    }
}
