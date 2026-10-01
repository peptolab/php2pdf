<?php

declare(strict_types=1);

namespace PhpPdf\Core;

use Closure;

use function restore_error_handler;
use function set_error_handler;

/**
 * Runs a callable with PHP warnings and notices suppressed, for functions that signal failure
 * through their return value but also emit a warning (fopen, gzuncompress, file_get_contents).
 *
 * @internal
 */
final class Silencer
{
    /**
     * @template T
     *
     * @param Closure(): T $call
     *
     * @return T
     */
    public static function run(Closure $call): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
