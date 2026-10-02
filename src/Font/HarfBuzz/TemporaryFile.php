<?php

declare(strict_types=1);

namespace Php2Pdf\Font\HarfBuzz;

use function is_file;
use function unlink;

/**
 * A temporary file, removed when this is released.
 *
 * @internal
 */
final readonly class TemporaryFile
{
    public function __construct(
        public string $path,
    ) {}

    public function __destruct()
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
