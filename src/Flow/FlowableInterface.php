<?php

declare(strict_types=1);

namespace PhpPdf\Flow;

use PhpPdf\Canvas\Canvas;
use PhpPdf\Geometry\Box;

/**
 * Content that flows through boxes across pages: a Textflow or a Table. Each fit places as much
 * as fits and remembers where it stopped; the caller decides where the rest goes.
 *
 * @api
 */
interface FlowableInterface
{
    /** Places as much of the remaining content as fits into the box. */
    public function fitInto(Canvas $canvas, Box $box): FlowResult;

    /** Whether everything has been placed. */
    public function isDone(): bool;

    /** Starts over from the beginning. */
    public function reset(): void;
}
