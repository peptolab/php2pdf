<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

use Php2Pdf\Exception\IoException;

use function file_get_contents;
use function is_file;
use function is_readable;
use function sprintf;
use function ucfirst;

/**
 * Reads whole files for the loaders: a missing or unreadable file is an IoException, never a PHP
 * warning, and a directory is a missing file.
 *
 * @internal
 */
final class FileReader
{
    /**
     * @param string $kind what the file is, for messages ("font file", "ICC profile")
     *
     * @throws IoException If the file does not exist or cannot be read.
     */
    public static function read(string $path, string $kind): string
    {
        if (! is_file($path)) {
            throw new IoException(sprintf('%s "%s" not found.', ucfirst($kind), $path));
        }
        $data = is_readable($path) ? Silencer::run(static fn(): string|false => file_get_contents($path)) : false;
        if (false === $data) {
            throw new IoException(sprintf('Cannot read %s "%s".', $kind, $path));
        }

        return $data;
    }
}
