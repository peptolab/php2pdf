<?php

declare(strict_types=1);

namespace Php2Pdf\Core\Output;

use Override;
use Php2Pdf\Exception\IoException;

use function fflush;
use function fwrite;
use function is_resource;
use function strlen;

/**
 * Writes to an existing PHP stream resource (php://output, a socket, a temp stream). The resource
 * is not closed by close().
 *
 * @api
 */
final class StreamOutput implements OutputInterface
{
    /** @var resource */
    private mixed $resource;

    private int $position = 0;

    /** @param resource $resource */
    public function __construct(mixed $resource)
    {
        if (! is_resource($resource)) {
            throw new IoException('StreamOutput expects an open stream resource.');
        }
        $this->resource = $resource;
    }

    #[Override]
    public function close(): void
    {
        fflush($this->resource);
    }

    #[Override]
    public function position(): int
    {
        return $this->position;
    }

    #[Override]
    public function write(string $bytes): void
    {
        $written = fwrite($this->resource, $bytes);
        if (strlen($bytes) !== $written) {
            throw new IoException('Short write to stream.');
        }
        $this->position += $written;
    }
}
