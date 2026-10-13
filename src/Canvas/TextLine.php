<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

use Php2Pdf\Core\Num;
use Php2Pdf\Geometry\Matrix;

use function abs;
use function round;

/**
 * Where the current line starts in a text object, as written, and the operator that moves it to
 * the next run's text matrix: absolute (Td from the object's start, or Tm) for the first run or
 * when the matrix turns or scales differently; otherwise relative to the line before, with T*
 * when only the leading moves it. Each move is measured from the position written, not from the
 * one asked for, so rounding never adds up along the lines of a box.
 *
 * @internal
 */
final class TextLine
{
    /**
     * The line's start as written, with the matrix's horizontal and vertical scale (each 1 or -1),
     * which relative moves are divided by; null when it is not known, after a Tm that turns or
     * scales the text.
     *
     * @var array{0: float, 1: float, 2: float, 3: float}|null
     */
    private ?array $start = null;

    /** Whether the text object has just begun, so its line matrix is the identity. */
    private bool $fresh = true;

    public function __construct(
        private readonly ContentStream $stream,
    ) {}

    /** Forgets the line: the next move begins a text object. */
    public function begin(): void
    {
        $this->start = null;
        $this->fresh = true;
    }

    /** The operator that moves the line to start at the text matrix $m. */
    public function to(Matrix $m): string
    {
        $flat  = 0.0 === $m->b && 0.0 === $m->c && 1.0 === abs($m->a) && 1.0 === abs($m->d);
        $start = $this->start;
        if (null !== $start && $flat && $start[0] === $m->a && $start[1] === $m->d) {
            return $this->move($m, $start);
        }
        $fresh       = $this->fresh;
        $this->fresh = false;
        $x           = round($m->e, precision: 6);
        $y           = round($m->f, precision: 6);
        $this->start = $flat ? [$m->a, $m->d, $x, $y] : null;

        return $fresh && 1.0 === $m->a && 1.0 === $m->d && $flat
            ? Num::format($x, 6) . ' ' . Num::format($y, 6) . ' Td'
            : "{$m->toPdf()} Tm";
    }

    /**
     * A move relative to the line's start, in text space: T* (setting the leading first when it
     * changes) when the line only moves down, otherwise Td.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $start
     */
    private function move(Matrix $m, array $start): string
    {
        [$a, $d, $x, $y] = $start;
        $dx          = round(($m->e - $x) / $a, precision: 6);
        $dy          = round(($m->f - $y) / $d, precision: 6);
        $this->start = [$a, $d, $x + ($dx * $a), $y + ($dy * $d)];
        if (0.0 !== $dx || 0.0 === $dy) {
            return Num::format($dx, 6) . ' ' . Num::format($dy, 6) . ' Td';
        }
        $leading = Num::format(-$dy, 6);

        return $this->stream->changes('TL', "{$leading} TL") ? "{$leading} TL T*" : 'T*';
    }
}
