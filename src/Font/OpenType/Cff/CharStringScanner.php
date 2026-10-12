<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType\Cff;

use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;

use function array_key_exists;
use function array_pop;
use function count;
use function in_array;
use function intdiv;
use function ord;
use function sprintf;
use function strlen;

/**
 * Walks Type 2 charstrings to find the global and local subroutines they call. Only what
 * reaching every call needs is interpreted: operands, stem hints (to size hint masks) and the
 * subroutine calls themselves.
 *
 * @internal
 */
final class CharStringScanner
{
    private const int MAX_DEPTH = 10;

    /** Escaped operators that compute or store operands; subroutine numbers could come from them. */
    private const array ARITHMETIC = [3, 4, 5, 9, 10, 11, 12, 14, 15, 18, 20, 21, 22, 23, 24, 26, 27, 28, 29, 30];

    /** @var array<int, true> */
    private array $global = [];

    /** @var array<int, array<int, true>> Font DICT => local subr => true */
    private array $local = [];

    /** @var list<int|float> */
    private array $stack = [];

    private int $stems = 0;
    private int $depth = 0;

    /** @param list<string> $globalSubrs */
    public function __construct(
        private readonly array $globalSubrs,
    ) {}

    /** The bias added to a subroutine number, from the number of subroutines. */
    public static function bias(int $count): int
    {
        return match (true) {
            $count < 1240 => 107,
            $count < 33_900 => 1131,
            default => 32_768,
        };
    }

    /** @return array<int, true> global subrs called */
    public function globalSubrs(): array
    {
        return $this->global;
    }

    /** @return array<int, true> local subrs of a Font DICT called */
    public function localSubrs(int $fd): array
    {
        return $this->local[$fd] ?? [];
    }

    /**
     * Records the subroutines a glyph's charstring calls.
     *
     * @param list<string> $localSubrs the subrs of the glyph's Font DICT
     *
     * @throws PdfException for charstrings the scanner cannot follow
     */
    public function scan(string $charString, int $fd, array $localSubrs): void
    {
        $this->stack = [];
        $this->stems = 0;
        $this->depth = 0;
        $this->run($charString, $fd, $localSubrs);
    }

    /**
     * Follows a callgsubr.
     *
     * @param list<string> $localSubrs
     *
     * @throws ParseException
     */
    private function callGlobal(int $fd, array $localSubrs): bool
    {
        $index                = $this->subrIndex($this->globalSubrs);
        $this->global[$index] = true;

        return $this->enter($this->globalSubrs[$index] ?? '', $fd, $localSubrs);
    }

    /**
     * Follows a callsubr.
     *
     * @param list<string> $localSubrs
     *
     * @throws ParseException
     */
    private function callLocal(int $fd, array $localSubrs): bool
    {
        $index                    = $this->subrIndex($localSubrs);
        $this->local[$fd][$index] = true;

        return $this->enter($localSubrs[$index] ?? '', $fd, $localSubrs);
    }

    /**
     * Runs a subroutine; returns whether it reached endchar.
     *
     * @param list<string> $localSubrs
     *
     * @throws ParseException
     */
    private function enter(string $subr, int $fd, array $localSubrs): bool
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new ParseException('Subroutines nest too deeply.');
        }
        $ended = $this->run($subr, $fd, $localSubrs);
        --$this->depth;

        return $ended;
    }

    /** Counts the stem hints whose arguments are on the stack. */
    private function hint(): void
    {
        $this->stems += intdiv(count($this->stack), num2: 2);
        $this->stack = [];
    }

    /** Pushes the operand at $i; returns its length in bytes. */
    private function operand(string $code, int $i, int $b0): int
    {
        $byte    = static fn(int $k): int => ord($code[$i + $k] ?? "\0");
        $operand = match (true) {
            28 === $b0 => [(($byte(1) << 8) | $byte(2)) - (($byte(1) & 0x80) << 9), 3],
            $b0 <= 246 => [$b0 - 139, 1],
            $b0 <= 250 => [(($b0 - 247) * 256) + $byte(1) + 108, 2],
            $b0 <= 254 => [(-($b0 - 251) * 256) - $byte(1) - 108, 2],
            default => [
                ((($byte(1) << 24) | ($byte(2) << 16) | ($byte(3) << 8) | $byte(4)) - (($byte(1) & 0x80) << 25))
                    / 65_536.0,
                5,
            ],
        };
        [$value, $length] = $operand;
        $this->stack[] = $value;

        return $length;
    }

    /**
     * Interprets a charstring or subroutine.
     *
     * @param list<string> $localSubrs
     *
     * @return bool whether endchar was reached
     *
     * @throws ParseException
     */
    private function run(string $code, int $fd, array $localSubrs): bool
    {
        $length = strlen($code);
        $i      = 0;
        while ($i < $length) {
            $b0 = ord($code[$i]);
            if ($b0 >= 32 || 28 === $b0) {
                $i += $this->operand($code, $i, $b0);
                continue;
            }
            ++$i;
            switch ($b0) {
                case 1:
                case 3:
                case 18:
                case 23:
                    $this->hint();
                    break;
                case 19:
                case 20:
                    $this->hint();
                    $i += intdiv($this->stems + 7, num2: 8);
                    break;
                case 10:
                    if ($this->callLocal($fd, $localSubrs)) {
                        return true;
                    }
                    break;
                case 29:
                    if ($this->callGlobal($fd, $localSubrs)) {
                        return true;
                    }
                    break;
                case 11:
                    return false;
                case 14:
                    if (count($this->stack) >= 4) {
                        throw new ParseException('endchar accent composition (seac) is not supported.');
                    }

                    return true;
                case 12:
                    $escaped = ord($code[$i] ?? "\0");
                    ++$i;
                    if (in_array($escaped, self::ARITHMETIC, strict: true)) {
                        throw new ParseException(sprintf('Charstring operator 12 %d is not supported.', $escaped));
                    }
                    $this->stack = [];
                    break;
                default:
                    $this->stack = [];
            }
        }

        return false;
    }

    /**
     * The subroutine the number on top of the stack calls.
     *
     * @param list<string> $subrs
     *
     * @throws ParseException
     */
    private function subrIndex(array $subrs): int
    {
        $value = array_pop($this->stack) ?? throw new ParseException('Subroutine call without a number.');
        $index = (int) $value + self::bias(count($subrs));
        if (! array_key_exists($index, $subrs)) {
            throw new ParseException(sprintf('Subroutine %d of %d does not exist.', $index, count($subrs)));
        }

        return $index;
    }
}
