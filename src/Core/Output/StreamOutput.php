<?php

declare(strict_types=1);

namespace PhpPdf\Core\Output;

use PhpPdf\Exception\IoException;

/**
 * Writes to an existing PHP stream resource (php://output, a socket, a temp stream). The resource
 * is not closed by close().
 */
final class StreamOutput implements Output
{
    private int $position = 0;

    /** @param resource $resource */
    public function __construct(private $resource)
    {
        if (!is_resource($resource)) {
            throw new IoException('StreamOutput expects an open stream resource.');
        }
    }

    public function write(string $bytes): void
    {
        $written = fwrite($this->resource, $bytes);
        if ($written !== strlen($bytes)) {
            throw new IoException('Short write to stream.');
        }
        $this->position += $written;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function close(): void
    {
        fflush($this->resource);
    }
}
