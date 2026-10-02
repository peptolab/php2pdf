<?php

declare(strict_types=1);

namespace Php2Pdf\Font\HarfBuzz;

use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\ExternalShaperInterface;

use function exec;
use function extension_loaded;

/**
 * Finds a way to use HarfBuzz: through FFI when the extension is enabled and the library loads,
 * else through the hb-shape command when it runs, else none.
 *
 * @api
 */
final class HarfBuzz
{
    private function __construct() {}

    public static function detect(?string $library = null, string $binary = 'hb-shape'): ?ExternalShaperInterface
    {
        $ffi = extension_loaded('ffi') ? self::ffi($library) : null;
        if (null !== $ffi) {
            return $ffi;
        }
        $output = [];
        $status = 1;
        exec($binary . ' --version 2>/dev/null', $output, $status);

        return 0 === $status ? new HarfBuzzCli($binary) : null;
    }

    /** HarfBuzz through FFI, or null when the library does not load. */
    private static function ffi(?string $library): ?HarfBuzzFfi
    {
        try {
            return new HarfBuzzFfi($library);
        } catch (PdfException) {
            return null;
        }
    }
}
