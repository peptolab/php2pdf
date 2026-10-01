<?php

declare(strict_types=1);

namespace PhpPdf\Core\Output;

use Override;
use PhpPdf\Core\Silencer;
use PhpPdf\Exception\IoException;

use function fclose;
use function fopen;
use function fwrite;
use function is_resource;
use function sprintf;
use function strlen;

/** @api */
final class FileOutput implements OutputInterface
{
    /** @var resource|null */
    private mixed $handle;

    private int $position = 0;

    public function __construct(
        public readonly string $path,
    ) {
        $handle = Silencer::run(static fn(): mixed => fopen($path, mode: 'wb'));
        if (! is_resource($handle)) {
            throw new IoException(sprintf('Cannot open "%s" for writing.', $path));
        }
        $this->handle = $handle;
    }

    #[Override]
    public function close(): void
    {
        if (null !== $this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    #[Override]
    public function position(): int
    {
        return $this->position;
    }

    #[Override]
    public function write(string $bytes): void
    {
        if (null === $this->handle) {
            throw new IoException('Output is closed.');
        }
        $written = fwrite($this->handle, $bytes);
        if (strlen($bytes) !== $written) {
            throw new IoException(sprintf('Short write to "%s".', $this->path));
        }
        $this->position += $written;
    }
}
