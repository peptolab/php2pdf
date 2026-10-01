<?php

declare(strict_types=1);

namespace PhpPdf\Core\Output;

use PhpPdf\Exception\IoException;

final class FileOutput implements Output
{
    /** @var resource|null */
    private $handle;

    private int $position = 0;

    public function __construct(public readonly string $path)
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new IoException(sprintf('Cannot open "%s" for writing.', $path));
        }
        $this->handle = $handle;
    }

    public function write(string $bytes): void
    {
        if ($this->handle === null) {
            throw new IoException('Output is closed.');
        }
        $written = fwrite($this->handle, $bytes);
        if ($written !== strlen($bytes)) {
            throw new IoException(sprintf('Short write to "%s".', $this->path));
        }
        $this->position += $written;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
