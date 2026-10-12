<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset\Output;

use function in_array;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function stream_wrapper_unregister;

/**
 * A stream wrapper (full-disk://) whose files open but take no bytes, as on a full disk, so a
 * write to one comes up short. Register it for a test and unregister it after.
 */
final class FullDiskStream
{
    public const string SCHEME = 'full-disk';

    /** @var resource|null set by PHP for every stream wrapper */
    public mixed $context = null;

    public static function register(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), strict: true)) {
            return;
        }
        stream_wrapper_register(self::SCHEME, self::class);
    }

    public static function unregister(): void
    {
        if (! in_array(self::SCHEME, stream_get_wrappers(), strict: true)) {
            return;
        }
        stream_wrapper_unregister(self::SCHEME);
    }

    public function stream_close(): void {}

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function unlink(string $path): bool
    {
        return true;
    }
}
