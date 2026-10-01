<?php

declare(strict_types=1);

namespace Php2Pdf\Core\Output;

use Override;

use function strlen;

/**
 * Buffers the document in memory. Handy for tests and HTTP responses of modest size.
 */
final class MemoryOutput implements OutputInterface
{
    private string $buffer = '';

    #[Override]
    public function close(): void {}

    public function contents(): string
    {
        return $this->buffer;
    }

    #[Override]
    public function position(): int
    {
        return strlen($this->buffer);
    }

    #[Override]
    public function write(string $bytes): void
    {
        $this->buffer .= $bytes;
    }
}
