<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use function extension_loaded;

/**
 * The extensions the transcoder may convert with: ext-imagick when there is one, else ext-gd
 *
 * @internal
 */
final readonly class TranscoderExtensions
{
    public function __construct(
        public bool $imagick,
        public bool $gd,
    ) {}

    public static function loaded(): self
    {
        return new self(extension_loaded('imagick'), extension_loaded('gd'));
    }
}
