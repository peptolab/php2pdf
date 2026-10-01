<?php

declare(strict_types=1);

namespace PhpPdf\Core\Output;

use Override;
use PhpPdf\Core\Silencer;
use PhpPdf\Exception\IoException;

use function fclose;
use function fopen;
use function fwrite;
use function is_file;
use function is_resource;
use function rename;
use function sprintf;
use function strlen;
use function uniqid;
use function unlink;

/**
 * Writes a PDF to a file. The bytes go to a temporary file beside it, which replaces the file
 * only when the document closes successfully: a document that fails part way leaves no file, or
 * the previous one, rather than a truncated PDF.
 *
 * @api
 */
final class FileOutput implements OutputInterface
{
    /** @var resource|null */
    private mixed $handle;

    private readonly string $temporary;
    private int $position = 0;

    public function __construct(
        public readonly string $path,
    ) {
        $this->temporary = sprintf('%s.%s.tmp', $path, uniqid(more_entropy: true));
        $handle          = Silencer::run(fn(): mixed => fopen($this->temporary, mode: 'xb'));
        if (! is_resource($handle)) {
            throw new IoException(sprintf('Cannot open "%s" for writing.', $path));
        }
        $this->handle = $handle;
    }

    /** @throws IoException when the finished file cannot be moved into place */
    #[Override]
    public function close(): void
    {
        if (null === $this->handle) {
            return;
        }
        fclose($this->handle);
        $this->handle = null;
        if (! Silencer::run(fn(): bool => rename($this->temporary, $this->path))) {
            if (is_file($this->temporary)) {
                Silencer::run(fn(): bool => unlink($this->temporary));
            }
            throw new IoException(sprintf('Cannot move the finished PDF to "%s".', $this->path));
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

    /** Removes the temporary file of a document that never closed. */
    public function __destruct()
    {
        if (null === $this->handle) {
            return;
        }
        fclose($this->handle);
        $this->handle = null;
        Silencer::run(fn(): bool => unlink($this->temporary));
    }
}
