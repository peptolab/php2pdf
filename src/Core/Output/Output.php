<?php

declare(strict_types=1);

namespace PhpPdf\Core\Output;

/**
 * A byte sink the writer streams into.
 */
interface Output
{
    public function write(string $bytes): void;

    /** Number of bytes written so far. */
    public function position(): int;

    public function close(): void;
}
