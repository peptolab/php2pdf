<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType\Cff;

use Php2Pdf\Exception\ParseException;

use function array_key_exists;
use function array_slice;
use function chr;
use function count;
use function implode;
use function intdiv;
use function ord;
use function sprintf;
use function str_pad;
use function str_split;
use function strlen;
use function substr;

/**
 * Converts CFF2 charstrings to Type 2 charstrings for the font's default instance: blend keeps
 * each value's default and drops its deltas, vsindex goes, glyphs end with endchar and
 * subroutines with return, as CFF requires. Each glyph is walked through the subroutines it
 * calls, so hint masks are copied with as many bytes as the stems declared before them; each
 * subroutine is converted the first time it is called.
 *
 * @internal
 */
final class Cff2CharStrings
{
    private const int MAX_NESTING = 10;

    private const int OP_HSTEM     = 1;
    private const int OP_VSTEM     = 3;
    private const int OP_CALLSUBR  = 10;
    private const int OP_RETURN    = 11;
    private const int OP_ESCAPE    = 12;
    private const int OP_ENDCHAR   = 14;
    private const int OP_VSINDEX   = 15;
    private const int OP_BLEND     = 16;
    private const int OP_HSTEMHM   = 18;
    private const int OP_HINTMASK  = 19;
    private const int OP_CNTRMASK  = 20;
    private const int OP_VSTEMHM   = 23;
    private const int OP_SHORTINT  = 28;
    private const int OP_CALLGSUBR = 29;

    /** @var array<int, string> global subroutine index => converted */
    private array $globalOut = [];

    /** @var array<int, array<int, string>> Font DICT => local subroutine index => converted */
    private array $localOut = [];

    /** @var array<string, int> "g12" or "l0:12" => the stems the subroutine declares */
    private array $subrStems = [];

    /** Stems declared so far in the glyph being converted. */
    private int $stems = 0;

    /** Regions per value for the current variation data. */
    private int $regions = 0;

    /**
     * @param list<string>             $globalSubrs
     * @param list<list<string>>       $localSubrs  per Font DICT
     * @param list<int>                $regionCounts regions per item variation data (vsindex)
     * @param list<int>                $defaultVsindex per Font DICT, from its Private DICT
     */
    public function __construct(
        private readonly array $globalSubrs,
        private readonly array $localSubrs,
        private readonly array $regionCounts,
        private readonly array $defaultVsindex,
    ) {}

    /** The bias added to a subroutine number, from how many subroutines there are. */
    private static function bias(int $count): int
    {
        return match (true) {
            $count < 1240 => 107,
            $count < 33_900 => 1131,
            default => 32_768,
        };
    }

    /**
     * @param list<string>       $subrs
     * @param array<int, string> $converted
     *
     * @return list<string>
     */
    private static function finish(array $subrs, array $converted): array
    {
        $out = [];
        foreach ($subrs as $i => $_) {
            $out[] = ($converted[$i] ?? '') . chr(self::OP_RETURN);
        }

        return $out;
    }

    private static function fixed(string $bytes): float
    {
        $value = 0;
        foreach (str_split(str_pad($bytes, length: 4, pad_string: "\0")) as $byte) {
            $value = ($value << 8) | ord($byte);
        }
        if ($value >= 0x8000_0000) {
            $value -= 0x1_0000_0000;
        }

        return $value / 65_536;
    }

    /**
     * A number token's value and length at $i, or null when the byte is an operator.
     *
     * @return array{0: float, 1: int}|null
     */
    private static function number(string $data, int $i): ?array
    {
        $b0 = ord($data[$i]);

        return match (true) {
            self::OP_SHORTINT === $b0 => [
                (float) self::signed16(ord($data[$i + 1] ?? "\0"), ord($data[$i + 2] ?? "\0")),
                3,
            ],
            $b0 >= 32 && $b0 <= 246 => [(float) ($b0 - 139), 1],
            $b0 >= 247 && $b0 <= 250 => [(float) ((($b0 - 247) * 256) + ord($data[$i + 1] ?? "\0") + 108), 2],
            $b0 >= 251 && $b0 <= 254 => [(float) ((-($b0 - 251) * 256) - ord($data[$i + 1] ?? "\0") - 108), 2],
            255 === $b0 => [self::fixed(substr($data, $i + 1, length: 4)), 5],
            default => null,
        };
    }

    private static function signed16(int $high, int $low): int
    {
        $value = ($high << 8) | $low;

        return $value >= 0x8000 ? $value - 0x1_0000 : $value;
    }

    /**
     * The global subroutines, converted where called and kept as a bare return otherwise.
     *
     * @return list<string>
     */
    public function globalSubrs(): array
    {
        return self::finish($this->globalSubrs, $this->globalOut);
    }

    /**
     * Converts a glyph's charstring, ending it with endchar.
     *
     * @throws ParseException
     */
    public function glyph(string $charString, int $fd): string
    {
        $this->stems   = 0;
        $this->regions = $this->regionCountFor($this->defaultVsindex[$fd] ?? 0);

        return $this->convert($charString, $fd, 0) . chr(self::OP_ENDCHAR);
    }

    /**
     * A Font DICT's local subroutines, converted where called.
     *
     * @return list<string>
     */
    public function localSubrs(int $fd): array
    {
        return self::finish($this->localSubrs[$fd] ?? [], $this->localOut[$fd] ?? []);
    }

    /**
     * Resolves a blend to the default instance: of the last n * (regions + 1) values (n being
     * the top of the stack), the first n are the defaults; the deltas after them go.
     *
     * @param list<string> $tokens
     * @param list<float>  $values
     *
     * @return array{0: list<string>, 1: list<float>}
     *
     * @throws ParseException
     */
    private function blend(array $tokens, array $values): array
    {
        $n     = (int) ($values[count($values) - 1] ?? 0);
        $total = $n * ($this->regions + 1);
        $start = count($values) - 1 - $total;
        if ($n < 0 || $start < 0) {
            throw new ParseException('CFF2 blend has too few operands.');
        }

        return [
            [...array_slice($tokens, offset: 0, length: $start), ...array_slice($tokens, $start, $n)],
            [...array_slice($values, offset: 0, length: $start), ...array_slice($values, $start, $n)],
        ];
    }

    /**
     * Converts a called subroutine the first time it is called, following its stems.
     *
     * @throws ParseException
     */
    private function call(int $op, int $biased, int $fd, int $depth): void
    {
        $global = self::OP_CALLGSUBR === $op;
        $subrs  = $global ? $this->globalSubrs : $this->localSubrs[$fd] ?? [];
        $index  = $biased + self::bias(count($subrs));
        if (! array_key_exists($index, $subrs)) {
            throw new ParseException(sprintf('CFF2 subroutine %d of %d does not exist.', $index, count($subrs)));
        }
        $key = $global ? "g{$index}" : "l{$fd}:{$index}";
        if (array_key_exists($key, $this->subrStems)) {
            $this->stems += $this->subrStems[$key];

            return;
        }
        $this->subrStems[$key] = 0;
        $before                = $this->stems;
        $converted             = $this->convert($subrs[$index], $fd, $depth + 1);
        $this->subrStems[$key] = $this->stems - $before;
        if ($global) {
            $this->globalOut[$index] = $converted;

            return;
        }
        $this->localOut[$fd][$index] = $converted;
    }

    /**
     * Converts one charstring or subroutine body. Numbers stay as written, except where blend
     * drops a value's deltas.
     *
     * @throws ParseException
     */
    private function convert(string $data, int $fd, int $depth): string
    {
        if ($depth > self::MAX_NESTING) {
            throw new ParseException('CFF2 subroutines nest too deeply.');
        }
        $out    = '';
        $tokens = [];
        $values = [];
        $length = strlen($data);
        $i      = 0;
        while ($i < $length) {
            $number = self::number($data, $i);
            if (null !== $number) {
                $tokens[] = substr($data, $i, $number[1]);
                $values[] = $number[0];
                $i        += $number[1];
                continue;
            }
            $op = ord($data[$i]);
            ++$i;
            if (self::OP_ESCAPE === $op) {
                $out    .= implode('', $tokens) . "\x0C" . ($data[$i] ?? "\0");
                $tokens = [];
                $values = [];
                ++$i;
                continue;
            }
            if (self::OP_BLEND === $op) {
                [$tokens, $values] = $this->blend($tokens, $values);
                continue;
            }
            if (self::OP_VSINDEX === $op) {
                $this->regions = $this->regionCountFor((int) ($values[count($values) - 1] ?? 0));
                $tokens        = [];
                $values        = [];
                continue;
            }
            if (self::OP_RETURN === $op || self::OP_ENDCHAR === $op) {
                break;
            }
            if (
                self::OP_HSTEM === $op
                || self::OP_VSTEM === $op
                || self::OP_HSTEMHM === $op
                || self::OP_VSTEMHM === $op
            ) {
                $this->stems += intdiv(count($values), num2: 2);
            }
            if (self::OP_HINTMASK === $op || self::OP_CNTRMASK === $op) {
                $this->stems += intdiv(count($values), num2: 2);
                $bytes       = intdiv($this->stems + 7, num2: 8);
                $out         .= implode('', $tokens) . chr($op) . substr($data, $i, $bytes);
                $i           += $bytes;
                $tokens      = [];
                $values      = [];
                continue;
            }
            if (self::OP_CALLSUBR === $op || self::OP_CALLGSUBR === $op) {
                $this->call($op, (int) ($values[count($values) - 1] ?? 0), $fd, $depth);
            }
            $out    .= implode('', $tokens) . chr($op);
            $tokens = [];
            $values = [];
        }

        return $out . implode('', $tokens);
    }

    private function regionCountFor(int $vsindex): int
    {
        return $this->regionCounts[$vsindex] ?? 0;
    }
}
