<?php

declare(strict_types=1);

namespace Php2Pdf\Core\Output;

/**
 * A byte sink the writer streams into.
 *
 * @api
 */
interface OutputInterface
{
    public function close(): void;

    /** Number of bytes written so far. */
    public function position(): int;

    public function write(string $bytes): void;
}
