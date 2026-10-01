<?php

declare(strict_types=1);

namespace PhpPdf\Core\Output;

/**
 * Buffers the document in memory. Handy for tests and HTTP responses of modest size.
 */
final class MemoryOutput implements Output
{
    private string $buffer = '';

    public function write(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    public function position(): int
    {
        return strlen($this->buffer);
    }

    public function close(): void
    {
    }

    public function contents(): string
    {
        return $this->buffer;
    }
}
